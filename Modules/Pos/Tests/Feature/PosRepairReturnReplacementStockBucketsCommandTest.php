<?php

namespace Modules\Pos\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Modules\Pos\Services\PosReturnReplacementStockRepairService;
use Modules\Pos\Tests\Feature\Support\PosTransactionFeatureTestCase;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;

/**
 * Coverage for the evidence-gated repair of historical stock-bucket drift left
 * behind by the POS Return replacement dispatch defect.
 */
class PosRepairReturnReplacementStockBucketsCommandTest extends PosTransactionFeatureTestCase
{
    protected Setting $setting;
    protected Location $location;
    protected $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = $this->createSetting('Repair Owner', false);
        [, $this->location] = $this->createTerminalWithLocation($this->setting);
        $this->actor = $this->createUserForSetting($this->setting, 'Repair Actor', []);
    }

    /** @test */
    public function dry_run_reports_the_affected_row_and_changes_no_data(): void
    {
        $fixture = $this->createFaultyReplacementHistory();

        $before = $this->stockSnapshot($fixture['product_id']);

        $this->artisan('pos:repair-return-replacement-stock-buckets')
            ->assertExitCode(0);

        $this->assertSame($before, $this->stockSnapshot($fixture['product_id']), 'Dry-run must not change stock.');
        $this->assertSame(0, $this->correctiveRows()->count(), 'Dry-run must create no corrective rows.');

        $candidates = app(PosReturnReplacementStockRepairService::class)->discover();

        $this->assertCount(1, $candidates);
        $this->assertSame(
            PosReturnReplacementStockRepairService::CLASSIFICATION_REPAIRABLE,
            $candidates[0]['classification']
        );
        $this->assertSame('quantity_non_tax', $candidates[0]['bucket']);
        $this->assertSame(-1, $candidates[0]['bucket_delta']);
        $this->assertContains($fixture['pos_return_id'], $candidates[0]['pos_return_ids']);
        $this->assertContains($fixture['sale_return_id'], $candidates[0]['sale_return_ids']);
        $this->assertContains($fixture['transaction_id'], $candidates[0]['transaction_ids']);
    }

    /** @test */
    public function apply_corrects_only_the_proven_bucket_and_records_audit_evidence(): void
    {
        $fixture = $this->createFaultyReplacementHistory();

        $before = $this->stockSnapshot($fixture['product_id']);

        $this->artisan('pos:repair-return-replacement-stock-buckets', ['--apply' => true])
            ->assertExitCode(0);

        $after = $this->stockSnapshot($fixture['product_id']);

        $this->assertSame($before['quantity_non_tax'] - 1, $after['quantity_non_tax']);
        $this->assertSame($before['quantity_tax'], $after['quantity_tax'], 'The untouched bucket must not move.');
        $this->assertSame(
            $after['quantity_non_tax'] + $after['quantity_tax']
                + $after['broken_quantity_non_tax'] + $after['broken_quantity_tax'],
            $after['quantity'],
            'Aggregate must be recomputed from its buckets.'
        );

        $corrective = $this->correctiveRows()->first();
        $this->assertNotNull($corrective, 'A corrective ledger row must exist.');
        $this->assertSame(-1, (int) $corrective->quantity_non_tax, 'The correction must be signed against the owner bucket.');
        $this->assertSame(0, (int) $corrective->quantity_tax);
        $this->assertSame($before['quantity'], (int) $corrective->previous_quantity_at_location);
        $this->assertSame($after['quantity'], (int) $corrective->after_quantity_at_location);

        // The reason carries the repair identity naming the faulty transaction.
        $this->assertStringContainsString(
            mb_strtoupper('txn:' . $fixture['transaction_id'], 'UTF-8'),
            (string) $corrective->reason
        );

        // The faulty source transaction stays immutable.
        $faulty = Transaction::query()->find($fixture['transaction_id']);
        $this->assertNotNull($faulty);
        $this->assertSame(1, (int) $faulty->quantity_non_tax, 'The historical ledger row must not be rewritten.');
    }

    /** @test */
    public function a_second_apply_makes_no_change_and_creates_no_duplicate_audit_record(): void
    {
        $this->createFaultyReplacementHistory();

        $this->artisan('pos:repair-return-replacement-stock-buckets', ['--apply' => true])->run();

        $afterFirst = $this->stockSnapshot(null);
        $auditsAfterFirst = $this->correctiveRows()->count();
        $ledgerAfterFirst = Transaction::query()->count();

        $this->artisan('pos:repair-return-replacement-stock-buckets', ['--apply' => true])
            ->assertExitCode(0);

        $this->assertSame($afterFirst, $this->stockSnapshot(null), 'A second apply must change no stock.');
        $this->assertSame($auditsAfterFirst, $this->correctiveRows()->count());
        $this->assertSame($ledgerAfterFirst, Transaction::query()->count());
    }

    /** @test */
    public function a_stock_change_after_dry_run_produces_a_conflict_without_overwrite(): void
    {
        $fixture = $this->createFaultyReplacementHistory();

        $service = app(PosReturnReplacementStockRepairService::class);
        $candidates = $service->discover();
        $this->assertSame(
            PosReturnReplacementStockRepairService::CLASSIFICATION_REPAIRABLE,
            $candidates[0]['classification']
        );

        // Someone else moves the row between dry-run and apply.
        $stock = ProductStock::query()
            ->where('product_id', $fixture['product_id'])
            ->where('location_id', $this->location->id)
            ->first();
        // A concurrent sale moves both the bucket and the aggregate together,
        // leaving the drift intact but invalidating the captured plan values.
        $stock->forceFill([
            'quantity_non_tax' => (int) $stock->quantity_non_tax - 1,
            'quantity' => (int) $stock->quantity - 1,
        ])->save();

        $snapshotBeforeApply = $this->stockSnapshot($fixture['product_id']);

        // Apply against the stale plan captured by the earlier dry-run.
        $results = $this->applyStalePlan($service, $candidates);

        $this->assertSame(
            PosReturnReplacementStockRepairService::CLASSIFICATION_CONFLICT,
            $results[0]['classification']
        );
        $this->assertSame(
            $snapshotBeforeApply,
            $this->stockSnapshot($fixture['product_id']),
            'A conflicting candidate must never be overwritten.'
        );
        $this->assertSame(0, $this->correctiveRows()->count());
    }

    /**
     * Product 182/location 6 in production: two missed decrements, but the
     * second receipt recomputed the aggregate from the already-inflated bucket,
     * so only 1 of the 2 shows up as bucket-vs-aggregate drift. The correction
     * must still be 2, anchored on the sellable serial count.
     *
     * @test
     */
    public function two_missed_decrements_are_both_corrected_even_when_a_receipt_hid_one(): void
    {
        $fixture = $this->createFaultyReplacementHistory(missedDecrements: 2);

        $candidates = app(PosReturnReplacementStockRepairService::class)->discover();

        $this->assertCount(1, $candidates);
        $this->assertSame(
            PosReturnReplacementStockRepairService::CLASSIFICATION_REPAIRABLE,
            $candidates[0]['classification']
        );

        // Bucket is 2 over the serial count; aggregate is only 1 over.
        $this->assertSame(-2, $candidates[0]['bucket_delta'], 'Both missed decrements must be corrected.');
        $this->assertSame(-1, $candidates[0]['aggregate_delta']);
        $this->assertCount(2, $candidates[0]['transaction_ids']);

        $this->artisan('pos:repair-return-replacement-stock-buckets', ['--apply' => true])
            ->assertExitCode(0);

        $after = $this->stockSnapshot($fixture['product_id']);

        $this->assertSame(9, $after['quantity_non_tax'], 'Bucket must land on the sellable serial count.');
        $this->assertSame(9, $after['quantity'], 'Aggregate must land on serials plus broken.');
    }

    /**
     * A non-serialized row has no independent physical count proving the
     * expected bucket balance, so it can never be "conclusively affected".
     * It is reported, never repaired, and no secondary inference is attempted.
     *
     * @test
     */
    public function a_non_serialized_lineage_match_stays_ambiguous_and_is_never_repaired(): void
    {
        $fixture = $this->createFaultyReplacementHistory();

        // Same proven replacement lineage and same bucket drift, but without
        // serials there is no authoritative balance source.
        \Modules\Product\Entities\Product::query()
            ->whereKey($fixture['product_id'])
            ->update(['serial_number_required' => false]);

        $before = $this->stockSnapshot($fixture['product_id']);

        $candidates = app(PosReturnReplacementStockRepairService::class)->discover();

        $this->assertCount(1, $candidates, 'The row is still reported.');
        $this->assertSame(
            PosReturnReplacementStockRepairService::CLASSIFICATION_AMBIGUOUS,
            $candidates[0]['classification']
        );
        $this->assertSame(0, $candidates[0]['bucket_delta'], 'No correction may be proposed.');
        $this->assertNotNull($candidates[0]['reason']);

        $this->artisan('pos:repair-return-replacement-stock-buckets', ['--apply' => true])->run();

        $this->assertSame(
            $before,
            $this->stockSnapshot($fixture['product_id']),
            'An ambiguous row must never be mutated by apply.'
        );
        $this->assertSame(0, $this->correctiveRows()->count());
    }

    /** @test */
    public function a_serialized_discrepancy_without_faulty_lineage_is_not_repairable(): void
    {
        // A stock row whose aggregate disagrees with its buckets, but with no
        // POS Return replacement lineage behind it at all.
        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'UNRELATED-' . uniqid(),
            'stock_qty' => 10,
        ]);

        $stock = ProductStock::query()
            ->where('product_id', $product->id)
            ->where('location_id', $this->location->id)
            ->first();
        $stock->forceFill(['quantity' => 14])->save();

        $before = $this->stockSnapshot($product->id);

        $candidates = app(PosReturnReplacementStockRepairService::class)->discover();

        $productIds = array_column($candidates, 'product_id');
        $this->assertNotContains(
            $product->id,
            $productIds,
            'A mismatch without conclusive replacement lineage must never be a candidate.'
        );

        $this->artisan('pos:repair-return-replacement-stock-buckets', ['--apply' => true])->run();

        $this->assertSame($before, $this->stockSnapshot($product->id), 'The unrelated row must remain unchanged.');
    }

    /**
     * Apply a plan captured before an external change, exercising the exact
     * current-value preconditions.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<int, array<string, mixed>>
     */
    protected function applyStalePlan(PosReturnReplacementStockRepairService $service, array $candidates): array
    {
        $applyCandidate = (function (array $candidate, ?int $actorId) {
            return $this->applyCandidate($candidate, $actorId);
        })->bindTo($service, PosReturnReplacementStockRepairService::class);

        return array_map(
            fn (array $candidate) => $applyCandidate($candidate, $this->actor->id),
            $candidates
        );
    }

    /**
     * Corrective ledger rows written by the repair (its audit evidence and
     * idempotency guard, since no repair table exists).
     */
    protected function correctiveRows(): \Illuminate\Database\Eloquent\Builder
    {
        return Transaction::query()->where(
            'reason',
            'like',
            mb_strtoupper(PosReturnReplacementStockRepairService::REPAIR_REASON_PREFIX, 'UTF-8') . '%'
        );
    }

    /**
     * @return array<string, int>
     */
    protected function stockSnapshot(?int $productId): array
    {
        $query = ProductStock::query()->where('location_id', $this->location->id);

        if ($productId !== null) {
            $query->where('product_id', $productId);
        }

        $rows = $query->orderBy('product_id')->get();

        return [
            'quantity' => (int) $rows->sum('quantity'),
            'quantity_tax' => (int) $rows->sum('quantity_tax'),
            'quantity_non_tax' => (int) $rows->sum('quantity_non_tax'),
            'broken_quantity_tax' => (int) $rows->sum('broken_quantity_tax'),
            'broken_quantity_non_tax' => (int) $rows->sum('broken_quantity_non_tax'),
        ];
    }

    /**
     * Reproduce the historical defect: a completed managed POS Return
     * replacement whose outbound DISPATCH_RETURN moved the aggregate but left
     * the owner bucket untouched.
     *
     * @return array<string, int>
     */
    protected function createFaultyReplacementHistory(int $missedDecrements = 1): array
    {
        $suffix = uniqid();

        $product = $this->createStockedProduct($this->setting, $this->location, [
            'product_code' => 'FAULTY-' . $suffix,
            'stock_qty' => 10,
        ]);

        $sale = \Modules\Sale\Entities\Sale::query()->create([
            'setting_id' => $this->setting->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in',
            'reference' => 'SO-RPR-' . $suffix,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'status' => 'DISPATCHED',
            'payment_status' => 'Paid',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ]);

        $saleDetail = \Modules\Sale\Entities\SaleDetails::query()->create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $posReturnIds = [];
        $saleReturnIds = [];
        $transactionIds = [];

        for ($n = 0; $n < $missedDecrements; $n++) {
        $suffixN = $suffix . '-' . $n;

        $posReturn = \Modules\Pos\Entities\PosReturn::query()->create([
            'setting_id' => $this->setting->id,
            'pos_transaction_id' => 1,
            'pos_checkout_id' => 1,
            'transaction_code' => 'TXN-RPR-' . $suffixN,
            'receipt_number' => 'RCP-RPR-' . $suffixN,
            'source_snapshot' => [],
            'source_snapshot_hash' => 'hash-' . $suffixN,
            'reference' => 'PR-RPR-' . $suffixN,
            'return_option' => \Modules\Pos\Entities\PosReturn::OPTION_PRODUCT_REPLACEMENT,
            'status' => \Modules\Pos\Entities\PosReturn::STATUS_COMPLETED,
            'approval_status' => 'APPROVED',
            'total_amount' => 1000,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);

        $saleReturn = \Modules\SalesReturn\Entities\SaleReturn::query()->create([
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'pos_return_id' => $posReturn->id,
            'sale_id' => $sale->id,
            'sale_reference' => $sale->reference,
            'return_type' => 'Replacement',
            'customer_id' => null,
            'customer_name' => 'Walk-in',
            'reference' => 'SR-RPR-' . $suffixN,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'COMPLETED',
            'approval_status' => 'APPROVED',
            'payment_status' => 'PENDING',
            'payment_method' => 'CASH',
            'date' => now()->toDateString(),
        ]);

        $line = \Modules\Pos\Entities\PosReturnLine::query()->create([
            'pos_return_id' => $posReturn->id,
            'pos_checkout_sale_id' => 1,
            'resolution' => \Modules\Pos\Entities\PosReturnLine::RESOLUTION_PRODUCT_REPLACEMENT,
            'sale_return_id' => $saleReturn->id,
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'source_setting_id' => $this->setting->id,
            'source_location_id' => $this->location->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'unit_price' => 1000,
            'line_total' => 1000,
            'stock_behavior' => \Modules\Pos\Entities\PosReturnLine::STOCK_BEHAVIOR_MANAGED,
            'replacement_product_id' => $product->id,
            'replacement_quantity' => 1,
        ]);

        \Modules\SalesReturn\Entities\SaleReturnDetail::query()->create([
            'sale_return_id' => $saleReturn->id,
            'pos_return_line_id' => $line->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'location_id' => $this->location->id,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'stock_behavior' => \Modules\Pos\Entities\PosReturnLine::STOCK_BEHAVIOR_MANAGED,
        ]);

        $dispatch = \Modules\Sale\Entities\Dispatch::query()->create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => \Modules\Sale\Entities\Dispatch::STATUS_APPROVED,
        ]);

        \Modules\Sale\Entities\DispatchDetail::query()->create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
            'pos_return_line_id' => $line->id,
            'is_inventory_managed' => true,
        ]);

        // The faulty outbound ledger row, reproduced exactly as production rows
        // 17381/17538/16590 look: the bucket column DOES carry the intended
        // quantity, which is why the ledger alone cannot identify the defect.
        // Only the resulting product_stocks drift below reveals it.
        $transaction = Transaction::query()->create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'quantity' => -1,
            'current_quantity' => 9,
            'broken_quantity' => 0,
            'location_id' => $this->location->id,
            'user_id' => $this->actor->id,
            'reason' => 'Dispatch replacement for Sale Return #' . $saleReturn->id,
            'type' => 'DISPATCH_RETURN',
            'previous_quantity' => 10,
            'after_quantity' => 9,
            'previous_quantity_at_location' => 10,
            'after_quantity_at_location' => 9,
            'quantity_non_tax' => 1,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $posReturnIds[] = (int) $posReturn->id;
        $saleReturnIds[] = (int) $saleReturn->id;
        $transactionIds[] = (int) $transaction->id;
        }

        // Mirror the defect's on-disk state: aggregate decremented, bucket not.
        // This bucket-exceeds-aggregate drift is the only observable signature.
        // Sellable serials (9) are the anchor. The bucket is over by one per
        // missed decrement; the aggregate is over by only one regardless,
        // because each later receipt recomputed it from the inflated bucket.
        DB::table('product_stocks')
            ->where('product_id', $product->id)
            ->where('location_id', $this->location->id)
            ->update([
                'quantity' => 10,
                'quantity_non_tax' => 9 + $missedDecrements,
            ]);

        $product->forceFill(['serial_number_required' => true])->save();

        for ($i = 0; $i < 9; $i++) {
            $this->createSerialNumber($product, $this->location, 'SN-RPR-' . $suffix . '-' . $i);
        }

        return [
            'product_id' => (int) $product->id,
            'pos_return_id' => $posReturnIds[0],
            'sale_return_id' => $saleReturnIds[0],
            'transaction_id' => $transactionIds[0],
            'pos_return_ids' => $posReturnIds,
            'transaction_ids' => $transactionIds,
        ];
    }
}
