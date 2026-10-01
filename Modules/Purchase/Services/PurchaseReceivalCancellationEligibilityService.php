<?php

namespace Modules\Purchase\Services;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseReceivingCompletion;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturn;

class PurchaseReceivalCancellationEligibilityService
{
    public function __construct(
        protected LegacyTransactionResolver $legacyResolver
    ) {}

    /**
     * Preview the purchase-level cancellation of every APPROVED and PENDING receival.
     * All-or-nothing: any blocker on any receival makes the whole action ineligible.
     *
     * @return array{
     *     eligible: bool,
     *     blockers: array<string>,
     *     purchase: Purchase,
     *     approved_notes: Collection<int, ReceivedNote>,
     *     pending_notes: Collection<int, ReceivedNote>,
     *     lines: array<array>,
     *     stock_requirements: array<array>,
     *     serialized_details: array<array>
     * }
     */
    public function preview(Purchase $purchase, ?string $reason = null, ?int $settingId = null): array
    {
        $blockers = [];

        $purchase->loadMissing('tenantSetting');

        $notes = ReceivedNote::query()
            ->where('po_id', $purchase->id)
            ->whereIn('status', [ReceivedNote::STATUS_APPROVED, ReceivedNote::STATUS_PENDING])
            ->orderBy('id')
            ->with([
                'location',
                'receivedNoteDetails.product',
                'receivedNoteDetails.tax',
                'receivedNoteDetails.location',
                'receivedNoteDetails.purchaseDetail',
                'receivedNoteDetails.transaction',
                'receivedNoteDetails.productSerialNumbers.histories',
                'receivedNoteDetails.productSerialNumbers.transferActiveClaim',
            ])
            ->get();

        $approvedNotes = $notes->where('status', ReceivedNote::STATUS_APPROVED)->values();
        $pendingNotes = $notes->where('status', ReceivedNote::STATUS_PENDING)->values();

        // 1. Tenancy check
        $effectiveSettingId = $settingId ?? (int) session('setting_id');
        if ($effectiveSettingId && (int) $purchase->setting_id !== $effectiveSettingId) {
            $blockers[] = "Purchase does not belong to the active setting.";
        }

        // 2. Ordinary purchase check (no consignment billing)
        if ($purchase->isConsignmentBilling()) {
            $blockers[] = "Consignment-billing purchases cannot have receivals cancelled. Goods are managed under consignment custody.";
        }

        // 3. Purchase archival check
        if ($purchase->archived_at) {
            $blockers[] = "Archived purchase #{$purchase->reference} cannot have receivals cancelled.";
        }

        // 4. Something to cancel
        if ($notes->isEmpty()) {
            $blockers[] = "Purchase #{$purchase->reference} has no APPROVED or PENDING receivals to cancel.";
        }

        // 5. Reason check
        if ($reason !== null && trim($reason) === '') {
            $blockers[] = "A non-empty cancellation reason is required.";
        }

        // 6. Check if Purchase has supplier-shortfall completion
        $hasShortfallCompletion = PurchaseReceivingCompletion::query()
            ->where('purchase_id', $purchase->id)
            ->exists();
        if ($hasShortfallCompletion) {
            $blockers[] = "Purchase #{$purchase->reference} has been completed via supplier shortfall completion and cannot have receivals cancelled.";
        }

        // 7. Check if Purchase has active/settled returns
        // Returns link to purchases through their details (purchase_return_details.po_id)
        $hasPurchaseReturns = PurchaseReturn::query()
            ->whereHas('purchaseReturnDetails', function ($q) use ($purchase) {
                $q->where('po_id', $purchase->id);
            })
            ->where(function ($q) {
                // Returns that have been approved or completed or are in progress
                $q->whereNull('approval_status')
                    ->orWhereRaw('LOWER(approval_status) <> ?', ['rejected']);
            })
            ->exists();
        if ($hasPurchaseReturns) {
            $blockers[] = "Purchase #{$purchase->reference} has purchase return records. Cancellation is blocked to prevent inventory divergence.";
        }

        // 8. Details validation for every APPROVED receival (PENDING ones have no inventory effect)
        $lines = [];
        $stockRequirementsMap = []; // key: "{product_id}_{location_id}", aggregated across all receivals
        $serializedDetails = [];

        foreach ($approvedNotes as $receivedNote) {
            foreach ($receivedNote->receivedNoteDetails as $detail) {
                $productId = $detail->product_id ?? $detail->purchaseDetail?->product_id;
                $locationId = $detail->location_id ?? $receivedNote->location_id;
                $taxId = $detail->tax_id ?? $detail->purchaseDetail?->tax_id;
                $qty = (float) $detail->quantity_received;

                if (!$productId) {
                    $blockers[] = "Receival #{$receivedNote->id} detail line #{$detail->id} has no resolvable product.";
                    continue;
                }

                // Check downstream UOM normalization lines
                if ($detail->uomNormalizationLines()->exists()) {
                    $blockers[] = "Receival #{$receivedNote->id} detail line #{$detail->id} for product [{$detail->display_product_name}] has been normalized in a UOM batch and cannot be cancelled.";
                }

                // Resolve original BUY transaction
                $sourceTxn = null;
                if ($detail->transaction) {
                    $sourceTxn = $detail->transaction;
                } else {
                    $resolution = $this->legacyResolver->resolve($detail);
                    if ($resolution['status'] === LegacyTransactionResolver::RESULT_MATCHED) {
                        $sourceTxn = $resolution['transaction'];
                    } elseif ($resolution['status'] === LegacyTransactionResolver::RESULT_AMBIGUOUS) {
                        $blockers[] = "Receival #{$receivedNote->id} detail line #{$detail->id} has ambiguous original inventory transactions ({$resolution['candidates']->count()} candidates).";
                    } else {
                        $blockers[] = "Receival #{$receivedNote->id} detail line #{$detail->id} could not be resolved to its original BUY inventory transaction.";
                    }
                }

                if ($sourceTxn && ($issue = self::sourceQuantityIssue($sourceTxn, $qty))) {
                    $blockers[] = "Receival #{$receivedNote->id} detail line #{$detail->id}: {$issue}.";
                }

                // Determine tax vs non-tax breakdown from the source transaction (fallback: detail tax_id)
                ['is_tax' => $isTax, 'quantity_tax' => $qtyTax, 'quantity_non_tax' => $qtyNonTax] = self::taxSplit($sourceTxn, $taxId, $qty);

                $lineData = [
                    'received_note_id' => $receivedNote->id,
                    'received_note_detail_id' => $detail->id,
                    'product_id' => $productId,
                    'product_name' => $detail->display_product_name,
                    'product_code' => $detail->display_product_code,
                    'location_id' => $locationId,
                    'tax_id' => $taxId,
                    'is_tax' => $isTax,
                    'quantity' => $qty,
                    'quantity_tax' => $qtyTax,
                    'quantity_non_tax' => $qtyNonTax,
                    'original_transaction_id' => $sourceTxn?->id,
                ];
                $lines[] = $lineData;

                // Aggregate stock requirements
                $bucketKey = "{$productId}_{$locationId}";
                if (!isset($stockRequirementsMap[$bucketKey])) {
                    $stockRequirementsMap[$bucketKey] = [
                        'product_id' => $productId,
                        'location_id' => $locationId,
                        'quantity' => 0.0,
                        'quantity_tax' => 0.0,
                        'quantity_non_tax' => 0.0,
                    ];
                }
                $stockRequirementsMap[$bucketKey]['quantity'] += $qty;
                $stockRequirementsMap[$bucketKey]['quantity_tax'] += $qtyTax;
                $stockRequirementsMap[$bucketKey]['quantity_non_tax'] += $qtyNonTax;

                // 9. Serial numbers validation
                $productModel = Product::find($productId);
                $isSerializedProduct = (bool) ($productModel?->serial_number_required ?? false);
                $serials = $detail->productSerialNumbers;

                if ($isSerializedProduct || $serials->isNotEmpty()) {
                    $serializedLineInfo = [
                        'received_note_detail_id' => $detail->id,
                        'serials' => [],
                    ];

                    // Serialized quantity must be a positive integer, not fractional
                    if ($qty <= 0 || floor($qty) != $qty) {
                        $blockers[] = "Receival #{$receivedNote->id} detail line #{$detail->id} for serialized product [{$detail->display_product_name}] has invalid fractional/non-positive quantity ({$qty}).";
                    }

                    // Must have exactly one eligible serial per received unit
                    $expectedSerialCount = (int) $qty;
                    if ($serials->count() !== $expectedSerialCount) {
                        $blockers[] = "Receival #{$receivedNote->id} detail line #{$detail->id} for serialized product [{$detail->display_product_name}] has {$serials->count()} linked serial(s), but received quantity is {$expectedSerialCount}.";
                    }

                    foreach ($serials as $serial) {
                        $serialBlockers = [];

                        // Must be ACTIVE
                        if ($serial->status !== ProductSerialNumber::STATUS_ACTIVE) {
                            $serialBlockers[] = "Status is {$serial->status} (expected ACTIVE)";
                        }

                        // Location must match receipt location
                        if ((int) $serial->location_id !== (int) $locationId) {
                            $serialBlockers[] = "Current location #{$serial->location_id} differs from receipt location #{$locationId}";
                        }

                        // Tax identity must match detail tax identity
                        $detailTaxId = $taxId ? (int) $taxId : null;
                        $serialTaxId = $serial->tax_id ? (int) $serial->tax_id : null;
                        if ($serialTaxId !== $detailTaxId) {
                            $serialBlockers[] = "Tax identity (tax_id #{$serialTaxId}) differs from receipt detail (tax_id #{$detailTaxId})";
                        }

                        // Must not be broken
                        if ($serial->is_broken) {
                            $serialBlockers[] = "Marked as broken";
                        }

                        // Must not be in return process
                        if ($serial->is_in_return_process) {
                            $serialBlockers[] = "Currently in return process";
                        }

                        // Must not be dispatched
                        if ($serial->dispatch_detail_id) {
                            $serialBlockers[] = "Has active dispatch #{$serial->dispatch_detail_id}";
                        }

                        // Must not have transfer active claim
                        if ($serial->transferActiveClaim) {
                            $serialBlockers[] = "In active transfer custody";
                        }

                        // Receipt must be latest receiving provenance and MUST exist
                        $latestReceiveHistory = $serial->histories
                            ->where('event_type', SerialNumberHistory::EVENT_RECEIVED)
                            ->sortByDesc('id')
                            ->first();

                        if (!$latestReceiveHistory) {
                            $serialBlockers[] = "Has no receipt history provenance";
                        } elseif (
                            $latestReceiveHistory->reference_type !== ReceivedNoteDetail::class
                            || (int) $latestReceiveHistory->reference_id !== (int) $detail->id
                        ) {
                            $serialBlockers[] = "Latest receiving provenance does not match this receiving detail";
                        }

                        if (!empty($serialBlockers)) {
                            $blockers[] = "Serial number '{$serial->serial_number}' is ineligible for reversal: " . implode(', ', $serialBlockers) . ".";
                        }

                        $serializedLineInfo['serials'][] = [
                            'id' => $serial->id,
                            'serial_number' => $serial->serial_number,
                            'status' => $serial->status,
                            'location_id' => $serial->location_id,
                            'tax_id' => $serial->tax_id,
                            'eligible' => empty($serialBlockers),
                            'issues' => $serialBlockers,
                        ];
                    }

                    $serializedDetails[] = $serializedLineInfo;
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

        // 10. Check exact stock availability at location and bucket, and global product quantity
        $stockRequirements = array_values($stockRequirementsMap);
        $globalRequirementsByProduct = [];

        foreach ($stockRequirements as $req) {
            $stock = ProductStock::query()
                ->where('product_id', $req['product_id'])
                ->where('location_id', $req['location_id'])
                ->first();

            $availTotal = $stock ? (float) $stock->quantity : 0.0;
            $availTax = $stock ? (float) $stock->quantity_tax : 0.0;
            $availNonTax = $stock ? (float) $stock->quantity_non_tax : 0.0;

            $product = Product::find($req['product_id']);
            $productName = $product ? $product->product_name : "Product #{$req['product_id']}";

            if ($availTotal < $req['quantity']) {
                $blockers[] = "Insufficient total stock for {$productName} at location #{$req['location_id']}. Required: {$req['quantity']}, Available: {$availTotal}.";
            }

            if ($req['quantity_tax'] > 0 && $availTax < $req['quantity_tax']) {
                $blockers[] = "Insufficient tax stock bucket for {$productName} at location #{$req['location_id']}. Required: {$req['quantity_tax']}, Available: {$availTax}.";
            }

            if ($req['quantity_non_tax'] > 0 && $availNonTax < $req['quantity_non_tax']) {
                $blockers[] = "Insufficient non-tax stock bucket for {$productName} at location #{$req['location_id']}. Required: {$req['quantity_non_tax']}, Available: {$availNonTax}.";
            }

            $globalRequirementsByProduct[$req['product_id']] = ($globalRequirementsByProduct[$req['product_id']] ?? 0.0) + $req['quantity'];
        }

        foreach ($globalRequirementsByProduct as $prodId => $reqGlobalQty) {
            $product = Product::find($prodId);
            $productName = $product ? $product->product_name : "Product #{$prodId}";
            $globalAvail = $product ? (float) $product->product_quantity : 0.0;

            if ($globalAvail < $reqGlobalQty) {
                $blockers[] = "Insufficient global product stock for {$productName}. Required: {$reqGlobalQty}, Available: {$globalAvail}.";
            }
        }

        return [
            'eligible' => empty($blockers),
            'blockers' => array_values(array_unique($blockers)),
            'purchase' => $purchase,
            'approved_notes' => $approvedNotes,
            'pending_notes' => $pendingNotes,
            'lines' => $lines,
            'stock_requirements' => $stockRequirements,
            'serialized_details' => $serializedDetails,
        ];
    }

    /**
     * Tax / non-tax bucket split for reversing a receival detail. Shared with the locked
     * revalidation in PurchaseReceivalCancellationService so both derive identical buckets.
     *
     * @return array{is_tax: bool, quantity_tax: float, quantity_non_tax: float}
     */
    public static function taxSplit(?Transaction $sourceTxn, $taxId, float $qty): array
    {
        if ($sourceTxn && $sourceTxn->quantity_tax > 0) {
            return ['is_tax' => true, 'quantity_tax' => (float) $sourceTxn->quantity_tax, 'quantity_non_tax' => (float) $sourceTxn->quantity_non_tax];
        }
        if ($sourceTxn && $sourceTxn->quantity_non_tax > 0) {
            return ['is_tax' => false, 'quantity_tax' => 0.0, 'quantity_non_tax' => (float) $sourceTxn->quantity_non_tax];
        }

        $isTax = !empty($taxId);

        return ['is_tax' => $isTax, 'quantity_tax' => $isTax ? $qty : 0.0, 'quantity_non_tax' => $isTax ? 0.0 : $qty];
    }

    /**
     * Quantity invariants a source BUY transaction must satisfy before its buckets are reversed.
     * Returns a problem description, or null when consistent.
     */
    public static function sourceQuantityIssue(Transaction $sourceTxn, float $receivedQuantity): ?string
    {
        $total = round((float) $sourceTxn->quantity, 6);
        $tax = round((float) $sourceTxn->quantity_tax, 6);
        $nonTax = round((float) $sourceTxn->quantity_non_tax, 6);

        if ($total !== round($receivedQuantity, 6)) {
            return "source transaction #{$sourceTxn->id} quantity {$total} differs from received quantity {$receivedQuantity}";
        }
        if ($tax < 0 || $nonTax < 0) {
            return "source transaction #{$sourceTxn->id} has negative bucket quantities (tax {$tax}, non-tax {$nonTax})";
        }
        if (round($tax + $nonTax, 6) !== $total) {
            return "source transaction #{$sourceTxn->id} bucket quantities (tax {$tax} + non-tax {$nonTax}) do not equal its quantity {$total}";
        }

        return null;
    }
}
