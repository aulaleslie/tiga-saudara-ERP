<?php

namespace Modules\Purchase\Services;

use App\Models\User;
use App\Services\Notification\DocumentNotificationService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Product\Entities\Transaction;
use Modules\Product\Services\ProductAveragePriceSynchronizer;
use Modules\Product\Services\ProductLastPurchasePriceSynchronizer;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteCancellation;
use Modules\Purchase\Entities\ReceivedNoteCancellationDetail;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Sale\Support\BackfillCostCalculator;
use Modules\Setting\Entities\Setting;

class PurchaseReceivalCancellationService
{
    public const TYPE_PURCHASE_RECEIVING_CANCELLED = 'PURCHASE_RECEIVING_CANCELLED';

    public function __construct(
        protected PurchaseReceivalCancellationEligibilityService $eligibilityService,
        protected PurchaseLifecycleService $lifecycleService,
        protected DocumentNotificationService $notificationService,
        protected HistoricalReplayEngine $replayEngine,
        protected LegacyTransactionResolver $legacyResolver,
        protected ProductAveragePriceSynchronizer $averagePriceSync,
        protected ProductLastPurchasePriceSynchronizer $lastPriceSync,
    ) {}

    /**
     * Atomically cancel every APPROVED and PENDING receival of a Purchase (all-or-nothing)
     * and return the Purchase to APPROVED.
     *
     * @return Collection<int, ReceivedNoteCancellation> One cancellation header per cancelled receival
     *
     * @throws Exception
     */
    public function cancel(Purchase $purchase, string $reason, User $actor, ?int $settingId = null): Collection
    {
        $settingId = $settingId ?? (int) session('setting_id');

        if (!$actor->can('purchases.receive.cancel')) {
            throw new Exception('Unauthorized: User does not have purchases.receive.cancel permission.');
        }

        return DB::transaction(function () use ($purchase, $reason, $actor, $settingId) {
            // 1. Authoritative Deterministic Locking in order:
            // Purchase -> ReceivedNotes -> ReceivedNoteDetails -> ProductStocks/Products -> Transactions -> Serials
            $purchase = Purchase::where('id', $purchase->id)
                ->lockForUpdate()
                ->firstOrFail();

            $allNotes = ReceivedNote::where('po_id', $purchase->id)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            $details = ReceivedNoteDetail::whereIn('received_note_id', $allNotes->pluck('id'))
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            // Lock products, stocks, and serials of approved receivals BEFORE eligibility runs, so no
            // sale, transfer, or return can change them between validation and mutation.
            $approvedDetails = $details->whereIn(
                'received_note_id',
                $allNotes->where('status', ReceivedNote::STATUS_APPROVED)->pluck('id')
            );
            $locked = $this->lockApprovedReceivalInventory($purchase, $approvedDetails, $allNotes);
            $lockedSerials = $locked['serials'];
            $serialIdsByDetail = $locked['serial_ids_by_detail'];

            // Re-validate eligibility of every affected receival under lock
            $preview = $this->eligibilityService->preview($purchase, $reason, $settingId);
            if (!$preview['eligible']) {
                $blockerMsg = implode('; ', $preview['blockers']);
                throw new Exception("Cancellation rejected: {$blockerMsg}");
            }

            // Authoritative revalidation reconstructed from the locked rows (not the preview payload):
            // a non-locking read in preview may observe a transaction snapshot older than the locks.
            // Execution uses only these locked-state lines; the preview must match them field for field.
            $lines = $this->assertLockedStateReversible($purchase, $preview, $allNotes, $approvedDetails, $locked);

            $now = now();
            $cancellations = collect();
            $cancellationByNote = [];

            foreach ($preview['approved_notes']->concat($preview['pending_notes'])->sortBy('id') as $note) {
                $wasApproved = $note->status === ReceivedNote::STATUS_APPROVED;
                $origin = $wasApproved
                    ? ReceivedNoteCancellation::ORIGIN_MANUAL_APPROVED
                    : ReceivedNoteCancellation::ORIGIN_MANUAL_PENDING;

                $cancellation = ReceivedNoteCancellation::create([
                    'received_note_id' => $note->id,
                    'purchase_id' => $purchase->id,
                    'setting_id' => $purchase->setting_id,
                    'previous_status' => $note->status,
                    'cancellation_origin' => $origin,
                    'cancelled_by' => $actor->id,
                    'reason' => $reason,
                    'cancelled_at' => $now,
                ]);

                $note->update([
                    'status' => ReceivedNote::STATUS_CANCELLED,
                    'cancelled_at' => $now,
                    'cancelled_by' => $actor->id,
                    'cancellation_reason' => $reason,
                    'cancellation_origin' => $origin,
                ]);

                $this->notificationService->resolveApproval($note);
                $this->notificationService->resolveRevision($note);

                $cancellations->push($cancellation);
                $cancellationByNote[$note->id] = $cancellation;
            }

            $affectedProductIds = [];
            $earliestDate = $preview['approved_notes']
                ->map(fn ($note) => $note->approved_at ?? $note->created_at)
                ->filter()
                ->min();

            if (!empty($lines)) {
                // Collect and lock products and product stocks in product_id order
                $productIds = collect($lines)->pluck('product_id')->unique()->sort()->values();
                $affectedProductIds = $productIds->all();

                $products = Product::whereIn('id', $productIds)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $locationIds = collect($lines)->pluck('location_id')->unique()->all();
                $stocks = ProductStock::whereIn('product_id', $productIds)
                    ->whereIn('location_id', $locationIds)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get()
                    ->groupBy(fn ($s) => "{$s->product_id}_{$s->location_id}");

                // Process lines: create reversal transactions, update ProductStock, update serials
                // Stock models are decremented in place, so later lines of other receivals see the reduced quantity
                foreach ($lines as $line) {
                    $cancellation = $cancellationByNote[$line['received_note_id']];
                    $rndId = $line['received_note_detail_id'];
                    $prodId = $line['product_id'];
                    $locId = $line['location_id'];
                    $qty = (float) $line['quantity'];
                    $qtyTax = (float) $line['quantity_tax'];
                    $qtyNonTax = (float) $line['quantity_non_tax'];
                    $origTxnId = $line['original_transaction_id'];

                    $product = $products->get($prodId);
                    $stockGroup = $stocks->get("{$prodId}_{$locId}");
                    $productStock = $stockGroup ? $stockGroup->first() : null;

                    if (!$productStock) {
                        throw new Exception("Product stock for product #{$prodId} at location #{$locId} not found.");
                    }

                    // Calculate before/after stock quantities
                    $prevStockQty = (float) $productStock->quantity;
                    $prevStockTax = (float) $productStock->quantity_tax;
                    $prevStockNonTax = (float) $productStock->quantity_non_tax;

                    if ($prevStockQty < $qty || ($qtyTax > 0 && $prevStockTax < $qtyTax) || ($qtyNonTax > 0 && $prevStockNonTax < $qtyNonTax)) {
                        throw new Exception("Insufficient stock bucket during authoritative execution for product #{$prodId}.");
                    }

                    $newStockQty = $prevStockQty - $qty;
                    $newStockTax = $prevStockTax - $qtyTax;
                    $newStockNonTax = $prevStockNonTax - $qtyNonTax;

                    $productStock->update([
                        'quantity' => $newStockQty,
                        'quantity_tax' => $newStockTax,
                        'quantity_non_tax' => $newStockNonTax,
                    ]);

                    // Update total product_quantity on products table
                    $prevProductQty = (float) $product->product_quantity;
                    if ($prevProductQty < $qty) {
                        throw new Exception("Insufficient global product quantity for product #{$prodId}. Required: {$qty}, Available: {$prevProductQty}.");
                    }
                    $newProductQty = $prevProductQty - $qty;
                    $product->update([
                        'product_quantity' => $newProductQty,
                    ]);

                    // Create cancellation line record
                    $cancellationDetail = ReceivedNoteCancellationDetail::create([
                        'cancellation_id' => $cancellation->id,
                        'received_note_detail_id' => $rndId,
                        'product_id' => $prodId,
                        'location_id' => $locId,
                        'tax_id' => $line['tax_id'],
                        'quantity' => $qty,
                        'quantity_tax' => $qtyTax,
                        'quantity_non_tax' => $qtyNonTax,
                        'original_transaction_id' => $origTxnId,
                    ]);

                    // Post negative reversal transaction
                    $reversalTxn = Transaction::create([
                        'product_id' => $prodId,
                        'setting_id' => $purchase->setting_id,
                        'location_id' => $locId,
                        'type' => self::TYPE_PURCHASE_RECEIVING_CANCELLED,
                        'quantity' => -$qty,
                        'current_quantity' => $newProductQty,
                        'previous_quantity' => $prevProductQty,
                        'after_quantity' => $newProductQty,
                        'previous_quantity_at_location' => $prevStockQty,
                        'after_quantity_at_location' => $newStockQty,
                        'quantity_tax' => -$qtyTax,
                        'quantity_non_tax' => -$qtyNonTax,
                        'broken_quantity' => 0,
                        'broken_quantity_tax' => 0,
                        'broken_quantity_non_tax' => 0,
                        'user_id' => $actor->id,
                        'reason' => "Pembatalan Penerimaan #{$line['received_note_id']} Pembelian #{$purchase->reference}: {$reason}",
                        'received_note_cancellation_detail_id' => $cancellationDetail->id,
                    ]);

                    $cancellationDetail->update([
                        'reversal_transaction_id' => $reversalTxn->id,
                    ]);

                    // Update serials from the authoritative locked linkage (validated above)
                    foreach ($serialIdsByDetail->get($rndId, collect()) as $serialId) {
                        $serial = $lockedSerials->get($serialId);

                        $serial->update([
                            'status' => ProductSerialNumber::STATUS_RECEIVING_CANCELLED,
                        ]);

                        SerialNumberHistory::create([
                            'product_serial_number_id' => $serial->id,
                            'event_type' => SerialNumberHistory::EVENT_PURCHASE_RECEIVING_CANCELLED,
                            'location_id' => $locId,
                            'reference_type' => ReceivedNoteCancellationDetail::class,
                            'reference_id' => $cancellationDetail->id,
                            'user_id' => $actor->id,
                            'note' => "Pembatalan Penerimaan Barang: {$reason}",
                        ]);
                    }
                }
            }

            // 2. Every effective receival is cancelled, so the Purchase returns to APPROVED
            $oldPurchaseStatus = $purchase->status;
            if ($oldPurchaseStatus !== Purchase::STATUS_APPROVED) {
                $purchase->update(['status' => Purchase::STATUS_APPROVED]);

                $this->lifecycleService->recordDerivedTransition(
                    purchase: $purchase,
                    oldStatus: $oldPurchaseStatus,
                    newStatus: Purchase::STATUS_APPROVED,
                    source: 'RECEIVING_CANCELLATION',
                    receivedNoteId: null,
                    actorUserId: $actor->id,
                    reason: $reason
                );
            }

            // 3. Synchronous Cost Reconciliation Replay
            if (!empty($affectedProductIds)) {
                $this->replayCosts($purchase, $affectedProductIds, $earliestDate);
            }

            return $cancellations;
        });
    }

    /**
     * Lock (in the documented order) purchase details, receival-serial links, products, stocks, source
     * BUY transactions, then serials and their receiving histories for the given approved receival details.
     *
     * @return array{purchase_details: Collection, serial_ids_by_detail: Collection, products: Collection, transactions_by_detail: Collection, legacy_resolution_by_detail: Collection, serials: Collection, latest_received_history: Collection}
     */
    protected function lockApprovedReceivalInventory(Purchase $purchase, Collection $approvedDetails, Collection $notes): array
    {
        $empty = [
            'purchase_details' => collect(),
            'serial_ids_by_detail' => collect(),
            'products' => collect(),
            'transactions_by_detail' => collect(),
            'legacy_resolution_by_detail' => collect(),
            'serials' => collect(),
            'latest_received_history' => collect(),
        ];
        if ($approvedDetails->isEmpty()) {
            return $empty;
        }

        $purchaseDetails = PurchaseDetail::whereIn('id', $approvedDetails->pluck('po_detail_id')->filter()->unique())
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get(['id', 'product_id', 'tax_id'])
            ->keyBy('id');

        // Authoritative current receival-serial linkage (locking read)
        $serialIdsByDetail = DB::table('received_note_detail_serial_numbers')
            ->whereIn('received_note_detail_id', $approvedDetails->pluck('id'))
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get(['received_note_detail_id', 'product_serial_number_id'])
            ->groupBy('received_note_detail_id')
            ->map(fn ($rows) => $rows->pluck('product_serial_number_id')->map(fn ($id) => (int) $id)->unique()->sort()->values());

        $productIds = $approvedDetails
            ->map(fn ($d) => $d->product_id ?? $purchaseDetails->get($d->po_detail_id)?->product_id)
            ->filter()
            ->unique()
            ->sort()
            ->values();
        $locationIds = $approvedDetails
            ->map(fn ($d) => $d->location_id ?? $notes->firstWhere('id', $d->received_note_id)?->location_id)
            ->filter()
            ->unique()
            ->values();

        $products = Product::whereIn('id', $productIds)->orderBy('id', 'asc')->lockForUpdate()->get()->keyBy('id');
        ProductStock::whereIn('product_id', $productIds)
            ->whereIn('location_id', $locationIds)
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();

        // Durable source BUY transactions (locking read)
        $transactionsByDetail = Transaction::whereIn('received_note_detail_id', $approvedDetails->pluck('id'))
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get()
            ->groupBy('received_note_detail_id');

        // Legacy sources (no durable link): lock every matching candidate and re-run the resolver's
        // uniqueness/chronology rules against that locked set, before serials are locked.
        $legacyResolutionByDetail = $approvedDetails
            ->reject(fn ($d) => $transactionsByDetail->has($d->id))
            ->sortBy('id')
            ->mapWithKeys(fn ($d) => [$d->id => $this->legacyResolver->resolveLocked(
                $d,
                $notes->firstWhere('id', $d->received_note_id),
                $purchase,
                $purchaseDetails->get($d->po_detail_id)?->product_id
            )]);

        $serialIds = $serialIdsByDetail->flatten()->unique()->sort()->values();
        if ($serialIds->isEmpty()) {
            return array_merge($empty, [
                'purchase_details' => $purchaseDetails,
                'serial_ids_by_detail' => $serialIdsByDetail,
                'products' => $products,
                'transactions_by_detail' => $transactionsByDetail,
                'legacy_resolution_by_detail' => $legacyResolutionByDetail,
            ]);
        }

        $serials = ProductSerialNumber::whereIn('id', $serialIds)
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $latestReceivedHistory = SerialNumberHistory::whereIn('product_serial_number_id', $serialIds)
            ->where('event_type', SerialNumberHistory::EVENT_RECEIVED)
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get(['id', 'product_serial_number_id', 'reference_type', 'reference_id'])
            ->groupBy('product_serial_number_id')
            ->map(fn ($rows) => $rows->last());

        return [
            'purchase_details' => $purchaseDetails,
            'serial_ids_by_detail' => $serialIdsByDetail,
            'products' => $products,
            'transactions_by_detail' => $transactionsByDetail,
            'legacy_resolution_by_detail' => $legacyResolutionByDetail,
            'serials' => $serials,
            'latest_received_history' => $latestReceivedHistory,
        ];
    }

    /**
     * Rebuild every execution line from the locked rows, independently of the preview payload, and
     * require the preview to describe exactly that state. Returns the authoritative execution lines.
     *
     * @throws Exception
     */
    protected function assertLockedStateReversible(Purchase $purchase, array $preview, Collection $notes, Collection $approvedDetails, array $locked): array
    {
        $lines = [];
        $blockers = [];

        // The preview must cover exactly the receivals that are effective under lock
        $lockedApprovedIds = $notes->where('status', ReceivedNote::STATUS_APPROVED)->pluck('id')->sort()->values()->all();
        $lockedPendingIds = $notes->where('status', ReceivedNote::STATUS_PENDING)->pluck('id')->sort()->values()->all();
        if ($preview['approved_notes']->pluck('id')->sort()->values()->all() !== $lockedApprovedIds
            || $preview['pending_notes']->pluck('id')->sort()->values()->all() !== $lockedPendingIds) {
            $blockers[] = 'Receival statuses changed after validation.';
        }

        $previewLines = collect($preview['lines'])->keyBy('received_note_detail_id');
        $previewSerialIds = collect($preview['serialized_details'])->mapWithKeys(fn ($sd) => [
            $sd['received_note_detail_id'] => collect($sd['serials'])->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
        ]);
        if ($previewLines->keys()->sort()->values()->all() !== $approvedDetails->pluck('id')->sort()->values()->all()) {
            $blockers[] = 'Receival details changed after validation.';
        }

        $claimedSerialIds = $locked['serials']->isEmpty()
            ? collect()
            : \Modules\Adjustment\Entities\TransferActiveSerialClaim::whereIn('product_serial_number_id', $locked['serials']->keys())
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->pluck('product_serial_number_id')
                ->map(fn ($id) => (int) $id);

        foreach ($approvedDetails as $detail) {
            $purchaseDetail = $locked['purchase_details']->get($detail->po_detail_id);
            $productId = $detail->product_id ?? $purchaseDetail?->product_id;
            $locationId = $detail->location_id ?? $notes->firstWhere('id', $detail->received_note_id)?->location_id;
            $taxId = $detail->tax_id ?? $purchaseDetail?->tax_id;
            $qty = (float) $detail->quantity_received;
            $serialIds = $locked['serial_ids_by_detail']->get($detail->id, collect());
            $label = "Receival #{$detail->received_note_id} detail line #{$detail->id}";

            // Source BUY transaction: durable link, or the legacy resolution re-run over locked candidates
            $previewLine = $previewLines->get($detail->id);
            $durable = $locked['transactions_by_detail']->get($detail->id, collect());
            $sourceTxn = null;
            if ($durable->count() > 1) {
                $blockers[] = "{$label} is linked to {$durable->count()} source transactions.";
            } elseif ($durable->count() === 1) {
                $sourceTxn = $durable->first();
            } else {
                $resolution = $locked['legacy_resolution_by_detail']->get($detail->id);
                if (($resolution['status'] ?? null) === LegacyTransactionResolver::RESULT_MATCHED) {
                    $sourceTxn = $resolution['transaction'];
                } elseif (($resolution['status'] ?? null) === LegacyTransactionResolver::RESULT_AMBIGUOUS) {
                    $blockers[] = "{$label} has ambiguous original inventory transactions ({$resolution['candidates']->count()} candidates) under lock.";
                }
            }

            if (!$productId) {
                $blockers[] = "{$label} has no resolvable product.";
            }
            if (!$sourceTxn) {
                $blockers[] = "{$label} could not be resolved to its original BUY inventory transaction under lock.";
            } elseif ($sourceTxn->type !== 'BUY'
                || (int) $sourceTxn->product_id !== (int) $productId
                || (int) $sourceTxn->location_id !== (int) $locationId
                || (int) $sourceTxn->setting_id !== (int) $purchase->setting_id
            ) {
                $blockers[] = "{$label} source transaction #{$sourceTxn->id} does not match the receival's product, location, or setting.";
            } elseif ($issue = PurchaseReceivalCancellationEligibilityService::sourceQuantityIssue($sourceTxn, $qty)) {
                $blockers[] = "{$label}: {$issue}.";
            }

            $split = PurchaseReceivalCancellationEligibilityService::taxSplit($sourceTxn, $taxId, $qty);
            $line = [
                'received_note_id' => $detail->received_note_id,
                'received_note_detail_id' => $detail->id,
                'product_id' => $productId,
                'location_id' => $locationId,
                'tax_id' => $taxId,
                'quantity' => $qty,
                'quantity_tax' => $split['quantity_tax'],
                'quantity_non_tax' => $split['quantity_non_tax'],
                'original_transaction_id' => $sourceTxn?->id,
            ];
            $lines[] = $line;

            // Every execution-relevant preview field must equal the locked reconstruction
            foreach ($line as $field => $value) {
                $previewValue = $previewLine[$field] ?? null;
                $same = is_float($value)
                    ? (float) $previewValue === $value
                    : ($previewValue === null ? $value === null : (string) $previewValue === (string) $value);
                if (!$same) {
                    $blockers[] = "{$label} {$field} changed after validation.";
                }
            }

            $isSerialized = (bool) ($locked['products']->get($productId)?->serial_number_required ?? false);
            if (!$isSerialized && $serialIds->isEmpty()) {
                continue;
            }

            if ($qty <= 0 || floor($qty) != $qty || $serialIds->count() !== (int) $qty) {
                $blockers[] = "{$label} has {$serialIds->count()} linked serial(s), but received quantity is {$qty}.";
            }

            if (($previewSerialIds->get($detail->id) ?? []) !== $serialIds->all()) {
                $blockers[] = "{$label} serial linkage changed after validation.";
            }

            foreach ($serialIds as $serialId) {
                $serial = $locked['serials']->get($serialId);
                if (!$serial) {
                    $blockers[] = "{$label}: serial #{$serialId} could not be locked for reversal.";
                    continue;
                }

                $latestReceipt = $locked['latest_received_history']->get($serialId);
                if ($serial->status !== ProductSerialNumber::STATUS_ACTIVE
                    || (int) $serial->location_id !== (int) $locationId
                    || ($taxId ? (int) $taxId : null) !== ($serial->tax_id ? (int) $serial->tax_id : null)
                    || $serial->is_broken
                    || $serial->is_in_return_process
                    || $serial->dispatch_detail_id
                    || $claimedSerialIds->contains($serial->id)
                ) {
                    $blockers[] = "Serial number '{$serial->serial_number}' changed after validation (status {$serial->status}) and is no longer reversible.";
                }

                if (!$latestReceipt
                    || $latestReceipt->reference_type !== ReceivedNoteDetail::class
                    || (int) $latestReceipt->reference_id !== (int) $detail->id
                ) {
                    $blockers[] = "Serial number '{$serial->serial_number}' latest receiving provenance does not match {$label}.";
                }
            }
        }

        // One original BUY movement can be reversed at most once across the whole purchase-level action
        $linesBySource = collect($lines)->filter(fn ($l) => !empty($l['original_transaction_id']))->groupBy('original_transaction_id');
        foreach ($linesBySource as $txnId => $sourceLines) {
            if ($sourceLines->count() > 1) {
                $blockers[] = "Source transaction #{$txnId} is ambiguous: it resolves to receival detail lines #"
                    . $sourceLines->pluck('received_note_detail_id')->implode(', #') . '.';
            }
        }

        if (!empty($blockers)) {
            throw new Exception('Cancellation rejected: ' . implode('; ', array_values(array_unique($blockers))));
        }

        return $lines;
    }

    /**
     * Replay costs for affected products from earliest affected receipt forward,
     * including updating downstream sale details HPP snapshots.
     */
    protected function replayCosts(Purchase $purchase, array $productIds, $fromDate): void
    {
        $fromDate = $fromDate instanceof Carbon ? $fromDate : Carbon::parse($fromDate);
        $boundaryDate = $fromDate->copy()->startOfDay();

        $eligibleSaleStatuses = ['Completed', 'COMPLETED', 'DISPATCHED', 'RETURNED PARTIALLY', 'RETURNED'];

        // Cache settings to bucket mappings
        $settingBucketMap = Setting::all()->mapWithKeys(function ($s) {
            return [$s->id => BackfillCostCalculator::classifyBucket($s->company_name)];
        });
        $purchaseBucket = $settingBucketMap->get($purchase->setting_id, 'rest');

        foreach ($productIds as $productId) {
            // Check for bundle items consumed as components of this product across the entire timeline
            $allBundleItems = \Modules\Sale\Entities\SaleBundleItem::query()
                ->where('product_id', $productId)
                ->with(['sale'])
                ->whereHas('sale', function ($q) use ($eligibleSaleStatuses) {
                    $q->whereIn('status', $eligibleSaleStatuses);
                })
                ->get(['id', 'sale_id', 'bundle_id', 'bundle_item_id', 'product_id', 'quantity', 'cost_snapshot_source']);

            $resolvedOwnerSettings = collect();
            if ($allBundleItems->isNotEmpty()) {
                $lineage = app(\Modules\Sale\Support\BundleItemOwnerLineageResolver::class)->resolve($allBundleItems);
                $resolvedOwnerSettings = $lineage['resolved'];
            }

            // Downstream bundle items eligible for cost snapshot update (on or after fromDate, belonging to affected bucket)
            // Bucket by the resolved physical owner (POS split sales may differ from the header setting);
            // unresolved items are excluded. Sales store document dates, so compare at day granularity.
            $eligibleBundleItems = $allBundleItems->filter(function ($bundleItem) use ($boundaryDate, $purchaseBucket, $settingBucketMap, $resolvedOwnerSettings) {
                if (!$bundleItem->sale || Carbon::parse($bundleItem->sale->date)->startOfDay()->lt($boundaryDate)) {
                    return false;
                }
                $ownerSettingId = $resolvedOwnerSettings->get($bundleItem->id);
                if ($ownerSettingId === null) {
                    return false;
                }
                return $settingBucketMap->get($ownerSettingId, 'rest') === $purchaseBucket;
            });

            $events = $this->replayEngine->collectTimelineEvents(
                productId: $productId,
                untilDate: null,
                includeBundleItems: $resolvedOwnerSettings->isNotEmpty(),
                bundleItemOwnerSettings: $resolvedOwnerSettings->isNotEmpty() ? $resolvedOwnerSettings : null,
            );

            $averageCost = $this->replayEngine->replayAverageCost($events, $boundaryDate);

            // Replay with bucket isolation to get per-sale historical unit cost snapshots
            $isolationResult = $this->replayEngine->replayWithBucketIsolation($events, $boundaryDate);
            $saleSnapshots = $isolationResult['sale_snapshots'] ?? [];

            // Update eligible downstream sales belonging to the affected replay bucket
            $sales = \Modules\Sale\Entities\Sale::query()
                ->whereDate('date', '>=', $boundaryDate)
                ->whereIn('status', $eligibleSaleStatuses)
                ->whereHas('saleDetails', function ($q) use ($productId) {
                    $q->where('product_id', $productId);
                })
                ->with(['saleDetails' => function ($q) use ($productId) {
                    $q->where('product_id', $productId);
                }])
                ->get()
                ->filter(function ($sale) use ($purchaseBucket, $settingBucketMap) {
                    $saleBucket = $settingBucketMap->get($sale->setting_id, 'rest');
                    return $saleBucket === $purchaseBucket;
                });

            foreach ($sales as $sale) {
                foreach ($sale->saleDetails as $saleDetail) {
                    // Preserve authoritative imported HPP snapshots
                    if ($saleDetail->cost_snapshot_source === 'HPP_SNAPSHOT_IMPORT') {
                        continue;
                    }

                    // Skip if no snapshot is available in replay (do not overwrite with zero)
                    if (!isset($saleSnapshots[$saleDetail->id])) {
                        continue;
                    }

                    $unitCost = (float) $saleSnapshots[$saleDetail->id];
                    $totalCost = round($unitCost * (float) $saleDetail->quantity, 2);

                    $saleDetail->cost_unit_snapshot = round($unitCost, 6);
                    $saleDetail->cost_total_snapshot = $totalCost;
                    $saleDetail->cost_snapshot_source = 'CANCELLATION_REPLAY';
                    $saleDetail->cost_snapshot_at = now();
                    $saleDetail->save();
                }
            }

            // Update eligible downstream bundle item components
            if ($eligibleBundleItems->isNotEmpty() && !empty($saleSnapshots)) {
                foreach ($eligibleBundleItems as $bundleItem) {
                    if ($bundleItem->cost_snapshot_source === 'HPP_SNAPSHOT_IMPORT') {
                        continue;
                    }

                    $eventKey = 'bi_' . $bundleItem->id;
                    if (!isset($saleSnapshots[$eventKey])) {
                        continue;
                    }

                    $unitCost = (float) $saleSnapshots[$eventKey];
                    $totalCost = round($unitCost * (float) $bundleItem->quantity, 2);

                    $bundleItem->cost_unit_snapshot = round($unitCost, 6);
                    $bundleItem->cost_total_snapshot = $totalCost;
                    $bundleItem->cost_snapshot_source = 'CANCELLATION_REPLAY';
                    $bundleItem->cost_snapshot_at = now();
                    $bundleItem->save();
                }
            }

            // Fetch last effective approved purchase detail price if available, otherwise reset to 0.0
            // Order chronologically by approved_at desc with id desc as a deterministic tie-breaker
            $lastApprovedRnDetail = ReceivedNoteDetail::query()
                ->where('product_id', $productId)
                ->whereHas('receivedNote', function ($q) {
                    $q->where('status', ReceivedNote::STATUS_APPROVED);
                })
                ->join('received_notes', 'received_note_details.received_note_id', '=', 'received_notes.id')
                ->orderBy('received_notes.approved_at', 'desc')
                ->orderBy('received_note_details.id', 'desc')
                ->select('received_note_details.*')
                ->first();

            $lastPrice = 0.0;
            if ($lastApprovedRnDetail && $lastApprovedRnDetail->purchaseDetail) {
                $lastPrice = (float) $lastApprovedRnDetail->purchaseDetail->price;
            }

            $this->averagePriceSync->syncAveragePurchasePrice($productId, $averageCost);
            $this->lastPriceSync->syncLastPurchasePrice($productId, $lastPrice);

            // Sync legacy product fields on products table directly
            $prod = Product::find($productId);
            if ($prod) {
                $prodUpdates = [];
                if ($averageCost !== null) {
                    $prodUpdates['average_purchase_price'] = $averageCost;
                }
                $prodUpdates['last_purchase_price'] = $lastPrice;
                $prod->update($prodUpdates);
            }
        }
    }
}
