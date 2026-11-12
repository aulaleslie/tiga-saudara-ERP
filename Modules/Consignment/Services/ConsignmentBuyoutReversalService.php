<?php

namespace Modules\Consignment\Services;

use App\Models\User;
use App\Services\SerialNumberHistoryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Consignment\Entities\ConsignmentActiveSerialClaim;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Entities\ConsignmentBuyoutAuditEvent;
use Modules\Consignment\Entities\ConsignmentBuyoutCostEvent;
use Modules\Consignment\Entities\ConsignmentBuyoutLine;
use Modules\Consignment\Entities\ConsignmentBuyoutMovement;
use Modules\Consignment\Entities\ConsignmentBuyoutOwnershipEvent;
use Modules\Consignment\Entities\ConsignmentBuyoutPurchaseLineage;
use Modules\Consignment\Entities\ConsignmentBuyoutReturnApportionment;
use Modules\Consignment\Entities\ConsignmentBuyoutReversal;
use Modules\Consignment\Entities\ConsignmentInventoryCoordination;
use Modules\Consignment\Entities\ConsignmentReceiving;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\Purchase;
use Modules\Setting\Entities\Location;

/**
 * INTERNAL, DISABLED BY DEFAULT (same flag as finalization). Dedicated FULL reversal of a finalized buyout.
 *
 * Eligibility is conservative and never inferred from current balances alone: no payment history at all (including
 * invalidated payments), no later movement of either leg's product/location, no later serial history or foreign claim,
 * no customer-return apportionment against the acquired sold units, and an unchanged carrying average.
 *
 * Lock order: linked Purchase first, then coordination rows and the buyout, then resources, then payment/dependency
 * evidence (so a concurrent payment either commits before - and blocks the reversal - or waits and is then denied).
 */
class ConsignmentBuyoutReversalService
{
    /** Test seam: called with a stage name ('liability','stock','serial','cost','audit') after its writes. */
    public ?\Closure $stageHook = null;

    /** @return array{eligible: bool, blockers: array<int, array{type: string, reference: string, reason: string}>} */
    public function preview(ConsignmentBuyout $buyout): array
    {
        $b = [];
        $add = function (string $type, string $ref, string $reason) use (&$b) {
            $b[] = ['type' => $type, 'reference' => $ref, 'reason' => $reason];
        };

        $buyout = $buyout->fresh();
        if ($buyout->status === ConsignmentBuyout::STATUS_REVERSED) {
            $add('STATE', "buyout:{$buyout->id}", 'Already reversed.');

            return ['eligible' => false, 'blockers' => $b];
        }
        if ($buyout->status !== ConsignmentBuyout::STATUS_FINALIZED) {
            $add('STATE', "buyout:{$buyout->id}", "Only a finalized buyout can be reversed (status {$buyout->status}).");

            return ['eligible' => false, 'blockers' => $b];
        }

        $purchase = $buyout->purchase_id ? Purchase::withArchived()->find($buyout->purchase_id) : null;
        if (! $purchase || $purchase->source_type !== Purchase::SOURCE_CONSIGNMENT_BUYOUT) {
            $add('PURCHASE', "buyout:{$buyout->id}", 'The linked CONSIGNMENT_BUYOUT Purchase is missing.');

            return ['eligible' => false, 'blockers' => $b];
        }
        if ($purchase->isCancelled()) {
            $add('PURCHASE', "purchase:{$purchase->id}", 'The Purchase is already cancelled.');
        }

        // Payments: any historical row blocks - active or invalidated - because supplier credits are out of scope.
        $payments = DB::table('purchase_payments')->where('purchase_id', $purchase->id)->count();
        if ($payments > 0) {
            $add('PAYMENT', "purchase:{$purchase->id}", "{$payments} payment record(s) exist (including invalidated ones); reversal after payment needs a supplier credit workflow.");
        }

        // Posting set must be complete before we undo it.
        $lines = $buyout->lines()->with(['serials', 'receivingDetail.receiving'])->orderBy('id')->get();
        if (ConsignmentBuyoutPurchaseLineage::where('consignment_buyout_id', $buyout->id)->count() !== $lines->count()) {
            $add('POSTING', "buyout:{$buyout->id}", 'The posting set is incomplete; reconcile manually.');
        }
        $legs = ConsignmentBuyoutMovement::where('consignment_buyout_id', $buyout->id)->where('kind', 'ACQUISITION')->get();
        $unsoldPlain = $lines->where('line_type', ConsignmentBuyoutLine::TYPE_UNSOLD_BUYOUT);
        if ($legs->count() !== $unsoldPlain->count() * 2) {
            $add('POSTING', "buyout:{$buyout->id}", 'Movement legs are incomplete; reconcile manually.');
        }

        // Receipts must still be eligible and recoverable.
        foreach ($lines as $line) {
            $receiving = $line->receivingDetail?->receiving;
            if (! $receiving || $receiving->status !== ConsignmentReceiving::STATUS_APPROVED) {
                $add('RECEIPT', "line:{$line->id}", 'The source receipt is no longer approved.');
            }
        }

        // Movements: nothing else may have touched either leg's product/location since the acquisition.
        $ownTxn = $legs->pluck('transaction_id')->filter()->all();
        foreach ($legs->groupBy(fn ($l) => $l->product_id . ':' . $l->location_id) as $key => $group) {
            [$productId, $locationId] = array_map('intval', explode(':', $key));
            $firstOwn = $group->pluck('transaction_id')->filter()->min();
            $later = Transaction::where('product_id', $productId)->where('location_id', $locationId)
                ->when($firstOwn, fn ($q) => $q->where('id', '>', $firstOwn))->whereNotIn('id', $ownTxn)->count();
            if ($later > 0) {
                $add('MOVEMENT', "product:{$productId}@location:{$locationId}", "{$later} later inventory transaction(s) exist; matching balances are not enough.");
            }
        }
        // Destination bucket sufficiency for the compensating leg.
        foreach ($legs->where('direction', 'IN') as $leg) {
            $stock = ProductStock::where('product_id', $leg->product_id)->where('location_id', $leg->location_id)->first();
            if (! $stock
                || ConsignmentBuyoutCapacityService::milli($stock->quantity_tax) < ConsignmentBuyoutCapacityService::milli($leg->quantity_tax)
                || ConsignmentBuyoutCapacityService::milli($stock->quantity_non_tax) < ConsignmentBuyoutCapacityService::milli($leg->quantity_non_tax)) {
                $add('STOCK', "line:{$leg->consignment_buyout_line_id}", 'The destination no longer holds the acquired quantity in the same buckets.');
            }
        }

        // Serials: still at the destination, still sellable and untouched since the acquisition.
        foreach ($lines->where('line_type', ConsignmentBuyoutLine::TYPE_UNSOLD_BUYOUT) as $line) {
            foreach ($line->serials as $bs) {
                $serial = ProductSerialNumber::find($bs->product_serial_number_id);
                $ref = "serial:{$bs->product_serial_number_id}";
                if (! $serial || (int) $serial->location_id !== (int) $line->destination_location_id
                    || strtoupper((string) ($serial->status ?: 'ACTIVE')) !== 'ACTIVE' || $serial->is_broken || $serial->is_in_return_process) {
                    $add('SERIAL', $ref, 'The serial is no longer active at the acquisition destination.');
                    continue;
                }
                $acq = SerialNumberHistory::where('product_serial_number_id', $serial->id)
                    ->where('event_type', SerialNumberHistory::EVENT_CONSIGNMENT_BUYOUT_ACQUIRED)->where('reference_type', ConsignmentBuyout::class)
                    ->where('reference_id', $buyout->id)->max('id');
                if ($acq && SerialNumberHistory::where('product_serial_number_id', $serial->id)->where('id', '>', $acq)->exists()) {
                    $add('SERIAL', $ref, 'The serial has later history after the acquisition.');
                }
                if (ConsignmentActiveSerialClaim::where('product_serial_number_id', $serial->id)->where('consignment_buyout_id', '!=', $buyout->id)->exists()) {
                    $add('SERIAL', $ref, 'The serial is claimed by another document.');
                }
            }
        }

        // Returns: acquired sold units that already came back (or are apportioned) cannot be silently un-acquired.
        $sourceIds = $lines->where('line_type', ConsignmentBuyoutLine::TYPE_SOLD_UNBILLED)->pluck('consignment_sold_source_id')->unique()->all();
        if ($sourceIds) {
            // Locking read: a declaration or receiving committed while this reversal waited must block it.
            $apportioned = ConsignmentBuyoutReservationGuard::latest(ConsignmentBuyoutReturnApportionment::whereIn('consignment_sold_source_id', $sourceIds))->count();
            if ($apportioned > 0) {
                $add('RETURN', 'sold sources ' . implode(',', $sourceIds), "{$apportioned} customer return(s) were apportioned against the acquired sold units.");
            }
        }

        // Cost: the shared average must be exactly what our acquisition left, with no later cost event.
        foreach (ConsignmentBuyoutCostEvent::where('consignment_buyout_id', $buyout->id)->where('kind', 'ACQUISITION')->get() as $ev) {
            $price = ProductPrice::where('product_id', $ev->product_id)->where('setting_id', $ev->setting_id)->first();
            if (! $price || abs((float) $price->average_purchase_price - (float) $ev->average_after) > 0.0049) {
                $add('COST', "product:{$ev->product_id}", 'The carrying average changed after this acquisition (another cost event).');
            }
            $laterEvent = ConsignmentBuyoutCostEvent::where('setting_id', $ev->setting_id)->where('product_id', $ev->product_id)->where('id', '>', $ev->id)->exists();
            if ($laterEvent) {
                $add('COST', "product:{$ev->product_id}", 'A later buyout cost event exists for this product.');
            }
            // Intervening changes of the shared average are detected from the append-only ledger, so an average that
            // moved and later returned to the same value is still caught.
            if ($ev->price_event_id !== null) {
                $intervening = DB::table('product_price_cost_events')->where('product_id', $ev->product_id)->where('setting_id', $ev->setting_id)
                    ->where('id', '>', $ev->price_event_id)->count();
                if ($intervening > 0) {
                    $add('COST', "product:{$ev->product_id}", "{$intervening} intervening change(s) of the carrying average were recorded after this acquisition (even if the value is the same today).");
                }
            }
        }

        return ['eligible' => $b === [], 'blockers' => $b];
    }

    public function reverse(ConsignmentBuyout $buyout, User $actor, string $reason): ConsignmentBuyout
    {
        if (! config('consignment.buyouts.finalization_enabled', false)) {
            throw new \DomainException('Buyout reversal is not enabled.');
        }
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reversal reason is required.');
        }
        if (! $actor->hasRole('Super Admin') && ! $actor->settings()->where('settings.id', $buyout->setting_id)->exists()) {
            throw new AuthorizationException('No access to this business.');
        }
        if (Gate::forUser($actor)->denies('consignments.buyouts.reverse')) {
            throw new AuthorizationException('Missing permission consignments.buyouts.reverse.');
        }
        // Undoing a remote acquisition changes the holding business's stock and cost: same delegation as acquiring it.
        (new ConsignmentBuyoutLifecycleService())->authorizeCustody($actor, $buyout);

        $purchaseId = ConsignmentBuyout::whereKey($buyout->id)->value('purchase_id');
        $purchase = null;
        $lifecycle = new ConsignmentBuyoutLifecycleService();

        return ConsignmentLockPlanner::run(fn () => $lifecycle->pairsOf($buyout), function ($cover) use ($buyout, $actor, $reason, $purchaseId, &$purchase) {
            // 2. (after the Purchase lock and the sorted coordination rows) the buyout.
            $locked = ConsignmentBuyout::whereKey($buyout->id)->lockForUpdate()->firstOrFail();
            $lines = ConsignmentBuyoutLine::where('consignment_buyout_id', $buyout->id)->with(['serials', 'receivingDetail.receiving'])->orderBy('id')->get();
            $cover((new ConsignmentBuyoutLifecycleService())->pairsOfLines($locked, $lines));

            // Repeat: a committed reversal is returned untouched.
            if ($locked->status === ConsignmentBuyout::STATUS_REVERSED) {
                return $locked;
            }

            // 3. Resources: locations, products, stocks, serials, prices (deterministic order).
            $locIds = $lines->flatMap(fn ($l) => [$l->source_location_id, $l->destination_location_id])->filter()->unique()->sort()->values()->all();
            Location::whereIn('id', $locIds)->orderBy('id')->lockForUpdate()->get();
            $productIds = $lines->pluck('product_id')->unique()->sort()->values()->all();
            $products = Product::whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $stocks = ProductStock::whereIn('product_id', $productIds)->whereIn('location_id', $locIds)->orderBy('id')->lockForUpdate()->get()
                ->keyBy(fn ($s) => $s->product_id . ':' . $s->location_id);
            $serialIds = $lines->flatMap(fn ($l) => $l->serials->pluck('product_serial_number_id'))->unique()->sort()->values()->all();
            $serials = $serialIds ? ProductSerialNumber::whereIn('id', $serialIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id') : collect();
            $owners = $lines->map(fn ($l) => $l->inventoryOwnerSettingId())->push((int) $locked->setting_id)->unique()->all();
            ProductPrice::whereIn('product_id', $productIds)->whereIn('setting_id', $owners)->orderBy('id')->lockForUpdate()->get();

            // 4. Payment and dependency evidence, read after the locks above.
            if ($purchaseId) {
                DB::table('purchase_payments')->where('purchase_id', $purchaseId)->lockForUpdate()->get();
            }
            $preview = $this->preview($locked);
            if (! $preview['eligible']) {
                $why = implode(' | ', array_map(fn ($x) => "[{$x['type']}] {$x['reference']}: {$x['reason']}", $preview['blockers']));
                throw new InvalidArgumentException("Buyout cannot be reversed: {$why}");
            }

            $now = Carbon::now();

            // ---- liability (scoped cancellation; original amounts and lineage stay) ------------------------
            Purchase::withBuyoutReversalScope((int) $purchase->id, function () use ($purchase) {
                $purchase->update(['status' => Purchase::STATUS_CANCELLED, 'due_amount' => 0]);
            });
            $this->stage('liability');

            // ---- compensating stock legs --------------------------------------------------------------------
            $legs = ConsignmentBuyoutMovement::where('consignment_buyout_id', $locked->id)->where('kind', 'ACQUISITION')->orderBy('id')->get();
            foreach ($legs as $leg) {
                $this->compensate($locked, $leg, $stocks, $products, $actor, $now);
            }
            $this->stage('stock');

            // ---- serials back to the consignment location + ownership reversal events ----------------------
            foreach ($lines as $line) {
                if ($line->serials->isNotEmpty() && $line->line_type === ConsignmentBuyoutLine::TYPE_UNSOLD_BUYOUT) {
                    foreach ($line->serials as $bs) {
                        $serial = $serials->get($bs->product_serial_number_id);
                        $serial->update(['location_id' => $line->source_location_id]);
                        SerialNumberHistoryService::record((int) $serial->id, SerialNumberHistory::EVENT_CONSIGNMENT_BUYOUT_REVERSED,
                            (int) $line->source_location_id, $locked, "Reversal pembelian putus {$locked->reference}: {$reason}", $actor->id);
                    }
                }
                if ($line->serials->isEmpty()) {
                    ConsignmentBuyoutOwnershipEvent::create([
                        'consignment_buyout_id' => $locked->id, 'consignment_buyout_line_id' => $line->id, 'inventory_owner_setting_id' => $line->inventory_owner_setting_id, 'product_serial_number_id' => null,
                        'consignment_receiving_detail_id' => $line->consignment_receiving_detail_id, 'kind' => 'REVERSAL',
                        'base_quantity' => $line->base_quantity, 'occurred_at' => $now,
                    ]);
                }
                foreach ($line->serials as $bs) {
                    ConsignmentBuyoutOwnershipEvent::create([
                        'consignment_buyout_id' => $locked->id, 'consignment_buyout_line_id' => $line->id, 'inventory_owner_setting_id' => $line->inventory_owner_setting_id, 'product_serial_number_id' => $bs->product_serial_number_id,
                        'consignment_receiving_detail_id' => $line->consignment_receiving_detail_id, 'kind' => 'REVERSAL',
                        'base_quantity' => 1, 'occurred_at' => $now,
                    ]);
                }
            }
            ConsignmentActiveSerialClaim::where('consignment_buyout_id', $locked->id)->delete();
            $this->stage('serial');

            // ---- carrying value restored exactly once per product ---------------------------------------------
            foreach (ConsignmentBuyoutCostEvent::where('consignment_buyout_id', $locked->id)->where('kind', 'ACQUISITION')->get() as $ev) {
                $price = ProductPrice::where('product_id', $ev->product_id)->where('setting_id', $ev->setting_id)->firstOrFail();
                $price->update(['average_purchase_price' => $ev->average_before]);
                ConsignmentBuyoutCostEvent::create([
                    'consignment_buyout_id' => $locked->id, 'setting_id' => $ev->setting_id, 'product_id' => $ev->product_id, 'kind' => 'REVERSAL',
                    'total_quantity' => $ev->total_quantity, 'acquired_quantity' => $ev->acquired_quantity, 'acquired_cost_total' => $ev->acquired_cost_total,
                    'average_before' => $ev->average_after, 'average_after' => $ev->average_before, 'rounding_residual' => 0, 'effective_at' => $now,
                ]);
            }
            $this->stage('cost');

            // ---- reversal record, status, audit ----------------------------------------------------------------
            $reversal = ConsignmentBuyoutReversal::create([
                'consignment_buyout_id' => $locked->id, 'purchase_id' => $purchase->id, 'reversed_by' => $actor->id, 'reason' => $reason,
                'eligibility_snapshot' => $preview, 'reversed_at' => $now,
            ]);
            $locked->update([
                'status' => ConsignmentBuyout::STATUS_REVERSED, 'reversal_id' => $reversal->id, 'reversed_by' => $actor->id, 'reversed_at' => $now,
            ]);
            ConsignmentBuyoutAuditEvent::create([
                'consignment_buyout_id' => $locked->id, 'action' => 'REVERSED', 'from_status' => ConsignmentBuyout::STATUS_FINALIZED,
                'to_status' => ConsignmentBuyout::STATUS_REVERSED, 'revision' => $locked->revision, 'revision_hash' => $locked->approved_revision_hash,
                'actor_id' => $actor->id, 'reason' => $reason, 'snapshot' => ['purchase_id' => $purchase->id, 'reversal_id' => $reversal->id],
            ]);
            $this->stage('audit');

            return $locked->fresh();
        }, function () use ($purchaseId, &$purchase) {
            // 1. Purchase first (matches payment code, which locks the Purchase before anything else).
            $purchase = $purchaseId ? Purchase::withArchived()->whereKey($purchaseId)->lockForUpdate()->first() : null;
        });
    }

    protected function compensate(ConsignmentBuyout $b, ConsignmentBuyoutMovement $leg, $stocks, $products, User $actor, Carbon $now): void
    {
        $stock = $stocks[$leg->product_id . ':' . $leg->location_id] ?? null;
        if (! $stock) {
            throw new \RuntimeException("Stock row missing for compensating leg of line #{$leg->consignment_buyout_line_id}.");
        }
        $sign = -1 * ($leg->base_quantity < 0 ? -1 : 1);        // opposite of the original leg
        $tax = $sign * abs((float) $leg->quantity_tax);
        $non = $sign * abs((float) $leg->quantity_non_tax);
        $product = $products->get($leg->product_id);
        // The compensating leg stays in the business that owned the acquisition (holding business for remote slices).
        $owner = (int) ($leg->inventory_owner_setting_id ?? $b->setting_id);
        $settingQty = (float) ProductStock::where('product_id', $leg->product_id)
            ->whereIn('location_id', DB::table('locations')->where('setting_id', $owner)->select('id'))->sum('quantity');
        $before = (float) $stock->quantity;

        $stock->quantity_tax = round((float) $stock->quantity_tax + $tax, 3);
        $stock->quantity_non_tax = round((float) $stock->quantity_non_tax + $non, 3);
        if ($stock->quantity_tax < 0 || $stock->quantity_non_tax < 0) {
            throw new \RuntimeException('Compensating leg would make a stock bucket negative.');
        }
        $stock->quantity = round((float) $stock->quantity_tax + (float) $stock->quantity_non_tax
            + (float) $stock->broken_quantity_tax + (float) $stock->broken_quantity_non_tax, 3);
        $stock->save();

        $txn = Transaction::create([
            'product_id' => $leg->product_id, 'setting_id' => $owner, 'type' => 'CONSIGNMENT_BUYOUT_REVERSAL',
            'quantity' => round($tax + $non, 3), 'current_quantity' => (float) $product->product_quantity, 'broken_quantity' => 0,
            'location_id' => $leg->location_id, 'user_id' => $actor->id,
            'reason' => "Reversal pembelian putus konsinyasi {$b->reference}",
            'previous_quantity' => $settingQty, 'after_quantity' => $settingQty,
            'previous_quantity_at_location' => $before, 'after_quantity_at_location' => (float) $stock->quantity,
            'quantity_tax' => $tax, 'quantity_non_tax' => $non, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0,
        ]);
        ConsignmentBuyoutMovement::create([
            'consignment_buyout_id' => $b->id, 'consignment_buyout_line_id' => $leg->consignment_buyout_line_id,
            'inventory_owner_setting_id' => $leg->inventory_owner_setting_id,
            'direction' => $leg->direction === 'OUT' ? 'IN' : 'OUT', 'kind' => 'REVERSAL', 'location_id' => $leg->location_id,
            'product_id' => $leg->product_id, 'transaction_id' => $txn->id, 'base_quantity' => round($tax + $non, 3),
            'quantity_tax' => $tax, 'quantity_non_tax' => $non, 'balance_before' => $before, 'balance_after' => (float) $stock->quantity,
            'actor_id' => $actor->id, 'posted_at' => $now,
        ]);
    }

    protected function stage(string $name): void
    {
        if ($this->stageHook) {
            ($this->stageHook)($name);
        }
    }
}
