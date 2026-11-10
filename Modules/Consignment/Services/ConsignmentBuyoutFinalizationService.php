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
use Modules\Consignment\Entities\ConsignmentBuyoutReservation;
use Modules\Consignment\Entities\ConsignmentInventoryCoordination;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Entities\ConsignmentSoldSource;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Setting\Entities\Location;

/**
 * INTERNAL, DISABLED BY DEFAULT. Atomically finalizes an APPROVED buyout: one CONSIGNMENT_BUYOUT Purchase, its
 * details and lineage, paired source/destination stock movements for unsold goods, serial moves, one carrying-value
 * replacement per business/product, consumed reservations and an audit event - all in one transaction.
 *
 * Nothing is trusted from the caller: the approved revision hash, reservations, pricing, availability and costing
 * are re-derived under locks. Repeated calls return the committed Purchase and write nothing.
 *
 * Exposure rule: this service refuses to run unless config('consignment.buyouts.finalization_enabled') is true.
 * It must stay off until every stock consumer respects reservations, the lock-retry protocol, ownership-aware
 * returns (group 7) and reversal safety (group 8) are all deployed together.
 */
class ConsignmentBuyoutFinalizationService
{
    public const STAGES = ['purchase', 'movement', 'serial', 'cost', 'audit'];

    /** Test seam: called with the stage name after that stage's writes; throwing aborts and rolls back everything. */
    public ?\Closure $stageHook = null;

    public function __construct(
        protected ?ConsignmentBuyoutLifecycleService $lifecycle = null,
        protected ?ConsignmentBuyoutPricingService $pricing = null,
        protected ?ConsignmentBuyoutCostingService $costing = null,
        protected ?ConsignmentBuyoutCapacityService $capacity = null
    ) {
        $this->capacity ??= new ConsignmentBuyoutCapacityService();
        $this->lifecycle ??= new ConsignmentBuyoutLifecycleService($this->capacity);
        $this->pricing ??= new ConsignmentBuyoutPricingService();
        $this->costing ??= new ConsignmentBuyoutCostingService();
    }

    public function finalize(ConsignmentBuyout $buyout, User $actor): Purchase
    {
        if (! config('consignment.buyouts.finalization_enabled', false)) {
            throw new \DomainException('Buyout finalization is not enabled.');
        }
        $hasUnsold = $buyout->lines()->where('line_type', ConsignmentBuyoutLine::TYPE_UNSOLD_BUYOUT)->exists();
        $this->authorize($actor, $buyout->setting_id, $hasUnsold);

        return ConsignmentLockPlanner::run(fn () => $this->lifecycle->pairsOf($buyout), function ($cover) use ($buyout, $actor) {
            $locked = ConsignmentBuyout::whereKey($buyout->id)->lockForUpdate()->firstOrFail();
            $cover($this->lifecycle->pairsOfLines($locked, $locked->lines()->get()));

            // Retry path: a committed finalization returns its Purchase after proving the posting set is complete.
            if ($locked->status === ConsignmentBuyout::STATUS_FINALIZED) {
                return $this->committedPurchase($locked);
            }
            if ($locked->status !== ConsignmentBuyout::STATUS_APPROVED) {
                throw new InvalidArgumentException("Only an approved buyout can be finalized (status {$locked->status}).");
            }
            if (! $this->lifecycle->approvedHashIsCurrent($locked)) {
                throw new InvalidArgumentException('The approved evidence changed after approval; cancel and re-approve the buyout.');
            }

            $lines = $locked->lines()->with(['serials', 'receivingDetail'])->orderBy('id')->get();
            $res = $this->lockResources($locked, $lines);

            $this->assertReservationsMatch($locked, $lines);

            // Revalidate availability/eligibility under the locks (own reservations excluded).
            $errors = $this->lifecycle->validateDocument($locked);
            if ($errors) {
                throw new InvalidArgumentException('Buyout can no longer be finalized: ' . implode(' ', $errors));
            }

            // Pricing is recomputed from the approved intent, never from submitted totals; stored acceptance must agree.
            $pricing = $this->pricing->calculate($locked);
            if (! $pricing['valid']) {
                throw new InvalidArgumentException('Approved pricing is no longer valid: ' . implode(' ', $pricing['blockers']));
            }
            foreach ($lines as $line) {
                $stored = $line->accepted_snapshot;
                $slice = collect($pricing['slices'])->firstWhere('line_id', $line->id);
                if ($stored !== null && (abs(($stored['payable'] ?? 0) - $slice['payable']) > 0.005
                        || abs(($stored['inventory_cost'] ?? 0) - ($slice['inventory_cost'] ?? 0)) > 0.005)) {
                    throw new InvalidArgumentException("Accepted terms for line #{$line->id} no longer match the recalculated pricing.");
                }
            }

            $meta = $this->metadata($locked);

            // Costing: ONE replacement per business/product across every slice (all destinations, all receipts).
            $costPlan = $this->costing->plan($locked, $pricing['slices']);
            foreach ($costPlan as $plan) {
                if ($plan['blocker']) {
                    throw new InvalidArgumentException("Carrying-value blocker for product #{$plan['product_id']}: {$plan['blocker']}");
                }
            }

            $now = Carbon::now();

            // ---- Purchase, details, lineage -------------------------------------------------------------
            $purchase = $this->createPurchase($locked, $pricing, $meta);
            $detailByLine = $this->createDetailsAndLineage($locked, $purchase, $pricing, $lines);
            $this->stage('purchase');

            // ---- Ownership movements (non-serialized) + sold ownership evidence ---------------------------
            $setting = (int) $locked->setting_id;
            $settingTotalBefore = $this->settingTotal($setting);
            $globalBefore = $this->globalTotals($lines);
            $this->postMovements($locked, $lines, $res, $actor, $now);
            $this->stage('movement');

            // ---- Serial moves + history --------------------------------------------------------------------
            $this->postSerials($locked, $lines, $res, $actor, $now);
            $this->stage('serial');

            if ($this->settingTotal($setting) !== $settingTotalBefore || $this->globalTotals($lines) !== $globalBefore) {
                throw new \RuntimeException('Ownership acquisition must not change physical quantity; posting aborted.');
            }

            // ---- Carrying value (once per product) ---------------------------------------------------------
            $this->applyCost($locked, $costPlan, $now);
            $this->stage('cost');

            // ---- Consume reservations, finalize, audit -----------------------------------------------------
            $this->lifecycle->consumeReservations($locked);
            $locked->update([
                'status' => ConsignmentBuyout::STATUS_FINALIZED,
                'purchase_id' => $purchase->id,
                'finalized_by' => $actor->id,
                'finalized_at' => $now,
            ]);
            ConsignmentBuyoutAuditEvent::create([
                'consignment_buyout_id' => $locked->id,
                'action' => 'FINALIZED',
                'from_status' => ConsignmentBuyout::STATUS_APPROVED,
                'to_status' => ConsignmentBuyout::STATUS_FINALIZED,
                'revision' => $locked->revision,
                'revision_hash' => $locked->approved_revision_hash,
                'actor_id' => $actor->id,
                'reason' => 'Finalized: Purchase ' . $purchase->reference,
                'snapshot' => [
                    'purchase_id' => $purchase->id,
                    'totals' => $pricing['totals'],
                    'cost_events' => array_values(array_map(fn ($p) => [
                        'product_id' => $p['product_id'], 'average_before' => $p['average_before'], 'average_after' => $p['average_after'],
                    ], $costPlan)),
                ],
            ]);
            $this->stage('audit');

            // Persist accepted terms (allowed while approved) now that everything agrees.
            $this->pricing->accept($locked->fresh());

            return $purchase->fresh();
        });
    }

    /* ----------------------------------------------------------------------------------- authorization */

    protected function authorize(User $actor, int $settingId, bool $hasUnsold): void
    {
        if (! $actor->hasRole('Super Admin') && ! $actor->settings()->where('settings.id', $settingId)->exists()) {
            throw new AuthorizationException('No access to this business.');
        }
        $needed = ['consignments.buyouts.finalize', 'consignments.billing.convert'];
        if ($hasUnsold) {
            $needed[] = 'consignments.buyouts.acquire_unsold';
        }
        foreach ($needed as $ability) {
            if (Gate::forUser($actor)->denies($ability)) {
                throw new AuthorizationException("Missing permission {$ability}.");
            }
        }
    }

    /* ----------------------------------------------------------------------------------- locking */

    /** @return array{stocks: array<string, ProductStock>, products: \Illuminate\Support\Collection, serials: \Illuminate\Support\Collection} */
    protected function lockResources(ConsignmentBuyout $buyout, $lines): array
    {
        $crd = $lines->pluck('consignment_receiving_detail_id')->unique()->sort()->values()->all();
        ConsignmentReceivingDetail::whereIn('id', $crd)->orderBy('id')->lockForUpdate()->get();

        $locIds = $lines->flatMap(fn ($l) => [$l->source_location_id, $l->destination_location_id])->filter()->unique()->sort()->values()->all();
        $locations = Location::whereIn('id', $locIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $productIds = $lines->pluck('product_id')->unique()->sort()->values()->all();
        $products = Product::whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        // Source and destination stock rows are acquired together, in id order; missing destination rows are created
        // here, serialized by the locked Product/Location rows and the coordination row.
        $unsold = $lines->where('line_type', ConsignmentBuyoutLine::TYPE_UNSOLD_BUYOUT);
        foreach ($unsold as $l) {
            foreach ([$l->source_location_id, $l->destination_location_id] as $locId) {
                $exists = ProductStock::where('product_id', $l->product_id)->where('location_id', $locId)->exists();
                if (! $exists) {
                    ProductStock::create([
                        'product_id' => $l->product_id, 'location_id' => $locId,
                        'quantity' => 0, 'quantity_tax' => 0, 'quantity_non_tax' => 0,
                        'broken_quantity' => 0, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0,
                    ]);
                }
            }
        }
        $stocks = ProductStock::whereIn('product_id', $productIds)->whereIn('location_id', $locIds)
            ->orderBy('id')->lockForUpdate()->get()->keyBy(fn ($s) => $s->product_id . ':' . $s->location_id)->all();

        $srcIds = $lines->pluck('consignment_sold_source_id')->filter()->unique()->sort()->values()->all();
        if ($srcIds) {
            ConsignmentSoldSource::whereIn('id', $srcIds)->orderBy('id')->lockForUpdate()->get();
        }
        $serialIds = $lines->flatMap(fn ($l) => $l->serials->pluck('product_serial_number_id'))->unique()->sort()->values()->all();
        $serials = $serialIds
            ? ProductSerialNumber::whereIn('id', $serialIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id')
            : collect();

        ProductPrice::whereIn('product_id', $productIds)->where('setting_id', $buyout->setting_id)->orderBy('id')->lockForUpdate()->get();

        return ['stocks' => $stocks, 'products' => $products, 'serials' => $serials, 'locations' => $locations];
    }

    /* ----------------------------------------------------------------------------------- verification */

    /** Active reservations must equal exactly what the approved lines require - nothing missing, nothing extra. */
    protected function assertReservationsMatch(ConsignmentBuyout $buyout, $lines): void
    {
        $active = ConsignmentBuyoutReservation::where('consignment_buyout_id', $buyout->id)->get();
        if ($active->where('status', '!=', ConsignmentBuyoutReservation::STATUS_ACTIVE)->isNotEmpty()) {
            throw new InvalidArgumentException('Reservations are not all active; the approval is no longer intact.');
        }

        $expected = [];
        foreach ($lines as $l) {
            $m = ConsignmentBuyoutCapacityService::milli($l->base_quantity);
            $expected['RECEIPT:' . $l->id] = $m;
            if ($l->line_type === ConsignmentBuyoutLine::TYPE_SOLD_UNBILLED) {
                $expected['SOLD_SOURCE:' . $l->id] = $m;
            } elseif ($l->serials->isEmpty()) {
                foreach (($l->original_snapshot['buckets'] ?? []) as $name => $q) {
                    if (ConsignmentBuyoutCapacityService::milli($q) > 0) {
                        $expected['PHYSICAL_BUCKET:' . $l->id . ':' . strtoupper($name)] = ConsignmentBuyoutCapacityService::milli($q);
                    }
                }
            }
            foreach ($l->serials as $s) {
                $expected['SERIAL:' . $l->id . ':' . $s->product_serial_number_id] = 1000;
            }
        }

        $actual = [];
        foreach ($active as $r) {
            $key = match ($r->resource_type) {
                'RECEIPT' => 'RECEIPT:' . $r->consignment_buyout_line_id,
                'SOLD_SOURCE' => 'SOLD_SOURCE:' . $r->consignment_buyout_line_id,
                'PHYSICAL_BUCKET' => 'PHYSICAL_BUCKET:' . $r->consignment_buyout_line_id . ':' . strtoupper((string) $r->tax_bucket),
                'SERIAL' => 'SERIAL:' . $r->consignment_buyout_line_id . ':' . $r->product_serial_number_id,
                default => 'UNKNOWN:' . $r->id,
            };
            $actual[$key] = ($actual[$key] ?? 0) + ConsignmentBuyoutCapacityService::milli($r->base_quantity);
        }
        ksort($expected);
        ksort($actual);
        if ($expected !== $actual) {
            throw new InvalidArgumentException('Active reservations no longer match the approved lines.');
        }

        $serialIds = $lines->flatMap(fn ($l) => $l->serials->pluck('product_serial_number_id'))->all();
        $claims = ConsignmentActiveSerialClaim::where('consignment_buyout_id', $buyout->id)->pluck('product_serial_number_id')->sort()->values()->all();
        $want = collect($serialIds)->map(fn ($i) => (int) $i)->sort()->values()->all();
        if ($claims !== $want) {
            throw new InvalidArgumentException('Serial claims no longer match the approved selection.');
        }
    }

    protected function metadata(ConsignmentBuyout $b): array
    {
        $m = $b->invoice_metadata ?? [];
        foreach (['invoice_date', 'supplier_invoice_number'] as $required) {
            if (empty($m[$required]) || trim((string) $m[$required]) === '') {
                throw new InvalidArgumentException("Invoice metadata [{$required}] is required to finalize.");
            }
        }

        return $m;
    }

    /* ----------------------------------------------------------------------------------- posting */

    protected function createPurchase(ConsignmentBuyout $b, array $pricing, array $meta): Purchase
    {
        $invoiceDate = Carbon::parse($meta['invoice_date']);
        $due = ! empty($meta['due_date']) ? Carbon::parse($meta['due_date']) : $invoiceDate->copy();
        $termId = $meta['payment_term_id'] ?? null;
        if ($termId && empty($meta['due_date'])) {
            $term = PaymentTerm::whereKey($termId)->lockForUpdate()->first();
            if ($term) {
                $due = $invoiceDate->copy()->addDays((int) $term->longevity);
            }
        }
        $t = $pricing['totals'];
        $zero = abs($t['total_amount']) < 0.005;

        return Purchase::create([
            'setting_id' => $b->setting_id,
            'supplier_id' => $b->supplier_id,
            'date' => $invoiceDate,
            'reporting_date' => ! empty($meta['reporting_date']) ? Carbon::parse($meta['reporting_date']) : $invoiceDate,
            'due_date' => $due,
            'payment_term_id' => $termId,
            'reference' => Purchase::generateReference((int) $b->setting_id, $invoiceDate),
            'supplier_purchase_number' => trim($meta['supplier_invoice_number']),
            'tax_ref_no' => $meta['tax_ref_no'] ?? null,
            'total_amount' => $t['total_amount'],
            'sub_total' => $t['sub_total'],
            'tax_amount' => $t['tax_amount'],
            'discount_percentage' => $t['global_discount_type'] === 'percentage' ? (float) (($b->pricing_intent['global_discount_value'] ?? 0)) : 0.0,
            'discount_amount' => $t['global_discount_amount'],
            'is_tax_included' => $pricing['is_tax_included'],
            'paid_amount' => 0.0,
            'due_amount' => $t['total_amount'],
            'status' => Purchase::STATUS_RECEIVED,
            'source_type' => Purchase::SOURCE_CONSIGNMENT_BUYOUT,
            'payment_status' => $zero ? Purchase::PAYMENT_STATUS_PAID : Purchase::PAYMENT_STATUS_UNPAID,
            'payment_method' => 'Bank Transfer',
            'note' => $meta['billing_notes'] ?? null,
        ]);
    }

    /** @return array<int, PurchaseDetail> line id => detail */
    protected function createDetailsAndLineage(ConsignmentBuyout $b, Purchase $purchase, array $pricing, $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $slice = collect($pricing['slices'])->firstWhere('line_id', $line->id);
            $product = Product::find($line->product_id);
            $detail = PurchaseDetail::create([
                'purchase_id' => $purchase->id,
                'product_id' => $line->product_id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'quantity' => $line->base_quantity,
                'price' => $slice['accepted']['unit_price'],
                'unit_price' => $slice['accepted']['unit_price'],
                // Ordinary Purchase convention: PKP detail sub_total is the gross, tax-inclusive amount.
                'sub_total' => $pricing['is_pkp'] ? $slice['accepted']['total'] : $slice['accepted']['sub_total'],
                'tax_id' => $slice['accepted']['tax_id'],
                'product_tax_amount' => $slice['accepted']['tax_amount'],
                'product_discount_type' => $slice['accepted']['discount_type'] ?? 'fixed',
                'product_discount_amount' => $slice['accepted']['discount_amount'] ?? 0.0,
                'pricing_source' => 'consignment_buyout',
            ]);
            ConsignmentBuyoutPurchaseLineage::create([
                'consignment_buyout_id' => $b->id,
                'consignment_buyout_line_id' => $line->id,
                'purchase_id' => $purchase->id,
                'purchase_detail_id' => $detail->id,
                'consignment_receiving_detail_id' => $line->consignment_receiving_detail_id,
                'product_id' => $line->product_id,
                'base_quantity' => $line->base_quantity,
                'original_amount' => $slice['original']['total'],
                'accepted_amount' => $slice['payable'],
                'inventory_cost_amount' => (float) ($slice['inventory_cost'] ?? 0),
                'allocation_snapshot' => ['document_discount' => $slice['document_discount'], 'line_type' => $line->line_type],
            ]);
            $out[$line->id] = $detail;
        }

        return $out;
    }

    protected function postMovements(ConsignmentBuyout $b, $lines, array $res, User $actor, Carbon $now): void
    {
        $occurred = $now;
        foreach ($lines as $line) {
            // Sold slices: no inventory effect, but the sold ownership trace is recorded for later return routing.
            if ($line->line_type === ConsignmentBuyoutLine::TYPE_SOLD_UNBILLED) {
                if ($line->serials->isEmpty()) {
                    ConsignmentBuyoutOwnershipEvent::create([
                        'consignment_buyout_id' => $b->id, 'consignment_buyout_line_id' => $line->id, 'product_serial_number_id' => null,
                        'consignment_receiving_detail_id' => $line->consignment_receiving_detail_id, 'kind' => 'ACQUISITION',
                        'base_quantity' => $line->base_quantity, 'occurred_at' => $occurred,
                    ]);
                }
                continue;
            }
            if ($line->serials->isNotEmpty()) {
                continue; // serialized unsold goods move in postSerials()
            }

            $buckets = [
                'quantity_tax' => (float) ($line->original_snapshot['buckets']['TAXED'] ?? 0),
                'quantity_non_tax' => (float) ($line->original_snapshot['buckets']['NON_TAXED'] ?? 0),
            ];
            $this->moveStock($b, $line, $res, $buckets, $actor, $now, null);
            ConsignmentBuyoutOwnershipEvent::create([
                'consignment_buyout_id' => $b->id, 'consignment_buyout_line_id' => $line->id, 'product_serial_number_id' => null,
                'consignment_receiving_detail_id' => $line->consignment_receiving_detail_id, 'kind' => 'ACQUISITION',
                'base_quantity' => $line->base_quantity, 'occurred_at' => $occurred,
            ]);
        }
    }

    protected function postSerials(ConsignmentBuyout $b, $lines, array $res, User $actor, Carbon $now): void
    {
        foreach ($lines as $line) {
            if ($line->serials->isEmpty()) {
                continue;
            }
            if ($line->line_type === ConsignmentBuyoutLine::TYPE_SOLD_UNBILLED) {
                foreach ($line->serials as $s) {
                    ConsignmentBuyoutOwnershipEvent::create([
                        'consignment_buyout_id' => $b->id, 'consignment_buyout_line_id' => $line->id, 'product_serial_number_id' => $s->product_serial_number_id,
                        'consignment_receiving_detail_id' => $line->consignment_receiving_detail_id, 'kind' => 'ACQUISITION',
                        'base_quantity' => 1, 'occurred_at' => $now,
                    ]);
                }
                continue;
            }

            $taxed = 0.0;
            $nonTaxed = 0.0;
            foreach ($line->serials as $s) {
                $serial = $res['serials']->get($s->product_serial_number_id);
                $serial->tax_id ? $taxed++ : $nonTaxed++;
            }
            $this->moveStock($b, $line, $res, ['quantity_tax' => $taxed, 'quantity_non_tax' => $nonTaxed], $actor, $now, $line->serials);

            foreach ($line->serials as $s) {
                $serial = $res['serials']->get($s->product_serial_number_id);
                // Receipt lineage (consignment_receiving_detail_id) is deliberately preserved; only the location changes.
                $serial->update(['location_id' => $line->destination_location_id]);
                SerialNumberHistoryService::record(
                    (int) $serial->id, SerialNumberHistory::EVENT_CONSIGNMENT_BUYOUT_ACQUIRED, (int) $line->destination_location_id, $b,
                    "Consignment buyout {$b->reference}", $actor->id
                );
                ConsignmentBuyoutOwnershipEvent::create([
                    'consignment_buyout_id' => $b->id, 'consignment_buyout_line_id' => $line->id, 'product_serial_number_id' => $serial->id,
                    'consignment_receiving_detail_id' => $line->consignment_receiving_detail_id, 'kind' => 'ACQUISITION',
                    'base_quantity' => 1, 'occurred_at' => $now,
                ]);
            }
        }
    }

    /**
     * Paired signed stock transactions (source out, destination in) with exact good tax/non-tax buckets. Broken stock,
     * global product quantity and the business total are untouched.
     *
     * @param array{quantity_tax: float, quantity_non_tax: float} $buckets
     */
    protected function moveStock(ConsignmentBuyout $b, ConsignmentBuyoutLine $line, array $res, array $buckets, User $actor, Carbon $now, $serials): void
    {
        $qty = round($buckets['quantity_tax'] + $buckets['quantity_non_tax'], 3);
        if (ConsignmentBuyoutCapacityService::milli($qty) !== ConsignmentBuyoutCapacityService::milli($line->base_quantity)) {
            throw new \RuntimeException("Line #{$line->id}: bucket split does not equal the acquired quantity.");
        }
        $product = $res['products']->get($line->product_id);
        $source = $res['stocks'][$line->product_id . ':' . $line->source_location_id] ?? null;
        $dest = $res['stocks'][$line->product_id . ':' . $line->destination_location_id] ?? null;
        if (! $source || ! $dest) {
            throw new \RuntimeException("Line #{$line->id}: stock rows are missing.");
        }
        foreach (['quantity_tax', 'quantity_non_tax'] as $col) {
            if ($buckets[$col] > 0 && ConsignmentBuyoutCapacityService::milli($source->{$col}) < ConsignmentBuyoutCapacityService::milli($buckets[$col])) {
                throw new \RuntimeException("Line #{$line->id}: source {$col} bucket is short; unrelated stock is never used.");
            }
        }

        $settingId = (int) $b->setting_id;
        $globalQty = (float) $product->product_quantity;
        $settingQty = $this->settingProductTotal($settingId, (int) $line->product_id);

        foreach ([['OUT', $source, -1, $line->source_location_id], ['IN', $dest, 1, $line->destination_location_id]] as [$direction, $stock, $sign, $locId]) {
            $before = ConsignmentBuyoutCapacityService::milli($stock->quantity);
            $stock->quantity_tax = round((float) $stock->quantity_tax + $sign * $buckets['quantity_tax'], 3);
            $stock->quantity_non_tax = round((float) $stock->quantity_non_tax + $sign * $buckets['quantity_non_tax'], 3);
            $stock->quantity = round((float) $stock->quantity_tax + (float) $stock->quantity_non_tax
                + (float) $stock->broken_quantity_tax + (float) $stock->broken_quantity_non_tax, 3);
            $stock->save();

            $txn = Transaction::create([
                'product_id' => $line->product_id,
                'setting_id' => $settingId,
                'type' => 'CONSIGNMENT_BUYOUT',
                'quantity' => $sign * $qty,
                'current_quantity' => $globalQty,          // global quantity is unchanged by an ownership transfer
                'broken_quantity' => 0,
                'location_id' => $locId,
                'user_id' => $actor->id,
                'reason' => "Pembelian putus konsinyasi {$b->reference} (" . ($direction === 'OUT' ? 'keluar dari konsinyasi' : 'masuk stok milik') . ')',
                'previous_quantity' => $settingQty,
                'after_quantity' => $settingQty,
                'previous_quantity_at_location' => ConsignmentBuyoutCapacityService::fromMilli($before),
                'after_quantity_at_location' => (float) $stock->quantity,
                'quantity_tax' => $sign * $buckets['quantity_tax'],
                'quantity_non_tax' => $sign * $buckets['quantity_non_tax'],
                'broken_quantity_tax' => 0,
                'broken_quantity_non_tax' => 0,
                'consignment_receiving_detail_id' => $line->consignment_receiving_detail_id,
            ]);

            ConsignmentBuyoutMovement::create([
                'consignment_buyout_id' => $b->id,
                'consignment_buyout_line_id' => $line->id,
                'direction' => $direction,
                'kind' => 'ACQUISITION',
                'location_id' => $locId,
                'product_id' => $line->product_id,
                'transaction_id' => $txn->id,
                'base_quantity' => $sign * $qty,
                'quantity_tax' => $sign * $buckets['quantity_tax'],
                'quantity_non_tax' => $sign * $buckets['quantity_non_tax'],
                'balance_before' => ConsignmentBuyoutCapacityService::fromMilli($before),
                'balance_after' => (float) $stock->quantity,
                'actor_id' => $actor->id,
                'posted_at' => $now,
            ]);
        }
    }

    protected function applyCost(ConsignmentBuyout $b, array $plan, Carbon $now): void
    {
        foreach ($plan as $productId => $p) {
            $price = ProductPrice::where('product_id', $productId)->where('setting_id', $b->setting_id)->lockForUpdate()->firstOrFail();
            $price->update(['average_purchase_price' => $p['average_after']]);   // last_purchase_price is never written
            $ledgerId = DB::table('product_price_cost_events')->where('product_id', $productId)->where('setting_id', $b->setting_id)->max('id');
            ConsignmentBuyoutCostEvent::create([
                'consignment_buyout_id' => $b->id,
                'setting_id' => $b->setting_id,
                'product_id' => $productId,
                'kind' => 'ACQUISITION',
                'total_quantity' => $p['total_quantity'],
                'acquired_quantity' => $p['acquired_quantity'],
                'acquired_cost_total' => $p['acquired_cost_total'],
                'average_before' => $p['average_before'],
                'average_after' => $p['average_after'],
                'rounding_residual' => $p['rounding_residual'],
                'price_event_id' => $ledgerId,
                'effective_at' => $now,
            ]);
        }
    }

    /* ----------------------------------------------------------------------------------- idempotency */

    /** A finalized buyout is complete or it is an integrity failure; never re-post. */
    protected function committedPurchase(ConsignmentBuyout $b): Purchase
    {
        $purchase = $b->purchase_id ? Purchase::withArchived()->find($b->purchase_id) : null;
        if (! $purchase || $purchase->source_type !== Purchase::SOURCE_CONSIGNMENT_BUYOUT) {
            throw new \RuntimeException("Finalized buyout #{$b->id} has no valid CONSIGNMENT_BUYOUT Purchase.");
        }
        $lines = $b->lines()->get();
        $unsoldNonSerial = $lines->where('line_type', ConsignmentBuyoutLine::TYPE_UNSOLD_BUYOUT);
        $lineage = ConsignmentBuyoutPurchaseLineage::where('consignment_buyout_id', $b->id)->count();
        $legs = ConsignmentBuyoutMovement::where('consignment_buyout_id', $b->id)->where('kind', 'ACQUISITION')->count();
        if ($lineage !== $lines->count() || $legs !== $unsoldNonSerial->count() * 2) {
            throw new \RuntimeException("Finalized buyout #{$b->id} has an incomplete posting set; manual reconciliation is required.");
        }

        return $purchase;
    }

    /* ----------------------------------------------------------------------------------- helpers */

    protected function stage(string $name): void
    {
        if ($this->stageHook) {
            ($this->stageHook)($name);
        }
    }

    protected function settingTotal(int $settingId): int
    {
        return ConsignmentBuyoutCapacityService::milli(ProductStock::whereIn('location_id', DB::table('locations')->where('setting_id', $settingId)->select('id'))->sum('quantity'));
    }

    protected function settingProductTotal(int $settingId, int $productId): float
    {
        return (float) ProductStock::where('product_id', $productId)
            ->whereIn('location_id', DB::table('locations')->where('setting_id', $settingId)->select('id'))->sum('quantity');
    }

    protected function globalTotals($lines): array
    {
        return Product::whereIn('id', $lines->pluck('product_id')->unique())->orderBy('id')->pluck('product_quantity', 'id')->map(fn ($v) => (string) $v)->all();
    }
}
