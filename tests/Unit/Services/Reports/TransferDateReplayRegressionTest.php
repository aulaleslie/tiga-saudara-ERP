<?php

namespace Tests\Unit\Services\Reports;

use App\Exports\StockMutationReportExport;
use App\Livewire\Reports\StockMutationReport;
use App\Services\Reports\InventoryDetailReportFilterData;
use App\Services\Reports\InventorySummaryReportFilterData;
use App\Services\Reports\InventorySummaryReportQueryService;
use App\Services\Reports\InventoryValuationReportFilterData;
use App\Services\Reports\InventoryValuationReportQueryService;
use App\Services\Reports\WarehouseStockValuationReportFilterData;
use App\Services\Reports\WarehouseStockValuationReportQueryService;
use App\Services\Reports\InventoryDetailReportQueryService;
use App\Services\Reports\WarehouseStockQuantityReportFilterData;
use App\Services\Reports\WarehouseStockQuantityReportQueryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class TransferDateReplayRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $user = \App\Models\User::factory()->create();
        $this->setting = Setting::factory()->create();
        $category = Category::create(['setting_id' => $this->setting->id, 'category_code' => 'C1', 'category_name' => 'Cat1', 'created_by' => $user->id]);
        Location::create(['id' => 1, 'setting_id' => $this->setting->id, 'name' => 'Origin']);
        Location::create(['id' => 2, 'setting_id' => $this->setting->id, 'name' => 'Dest']);
        $this->product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_name' => 'P', 'product_code' => 'P1',
            'product_price' => 100, 'product_cost' => 100, 'stock_managed' => true,
        ]);
        $this->tx('BUY', 1, 10, '#BUY-1', '2026-09-01 10:00:00');
    }

    private function tx(string $type, int $loc, float $qty, string $reason, string $createdAt): void
    {
        $t = Transaction::create([
            'setting_id' => $this->setting->id, 'product_id' => $this->product->id,
            'location_id' => $loc, 'type' => $type,
            'current_quantity' => 0, 'previous_quantity' => 0, 'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 0, 'quantity_non_tax' => 0, 'quantity_tax' => 0,
            'broken_quantity' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0,
            'quantity' => $qty, 'after_quantity' => 0, 'reason' => $reason,
            'date' => substr($createdAt, 0, 10),
        ]);
        $t->created_at = Carbon::parse($createdAt);
        $t->save();
    }

    private function transfer(?string $dispatched, ?string $received, string $txAt): void
    {
        $id = DB::table('transfers')->insertGetId([
            'document_number' => 'TRF-0001',
            'origin_location_id' => 1, 'destination_location_id' => 2,
            'created_by' => 1, 'status' => 'RECEIVED',
            'dispatched_at' => $dispatched, 'received_at' => $received,
            'created_at' => $txAt, 'updated_at' => $txAt,
        ]);
        $this->tx('TRF', 1, -3, "Transfer #{$id}", $txAt);
        $this->tx('TRF', 2, 3, "Transfer #{$id}", $txAt);
    }

    private function qty(string $asOf): array
    {
        $rows = (new WarehouseStockQuantityReportQueryService())->build(
            new WarehouseStockQuantityReportFilterData(asOfDate: $asOf, scopeSettingId: $this->setting->id)
        );
        $r = $rows->first();

        return [$r->qty_loc_1, $r->qty_loc_2];
    }

    public function test_warehouse_quantity_uses_dispatch_and_receipt_dates_across_boundary(): void
    {
        $this->transfer('2026-10-31 09:00:00', '2026-11-02 09:00:00', '2026-10-31 09:00:00');

        $this->assertEquals([7.0, 0.0], $this->qty('2026-10-31'));
        $this->assertEquals([7.0, 0.0], $this->qty('2026-11-01'));
        $this->assertEquals([7.0, 3.0], $this->qty('2026-11-02'));
    }

    public function test_missing_received_at_falls_back_to_transaction_created_at_not_dispatch(): void
    {
        $this->transfer('2026-10-31 09:00:00', null, '2026-10-30 09:00:00');

        // Outgoing leg dated by dispatch (Oct 31); incoming has no receipt, so uses created_at (Oct 30).
        $this->assertEquals([10.0, 3.0], $this->qty('2026-10-30'));
        $this->assertEquals([7.0, 3.0], $this->qty('2026-10-31'));
    }

    public function test_missing_dispatched_at_falls_back_to_transaction_created_at_not_receipt(): void
    {
        $this->transfer(null, '2026-11-05 09:00:00', '2026-10-30 09:00:00');

        $this->assertEquals([7.0, 0.0], $this->qty('2026-10-31'));
        $this->assertEquals([7.0, 3.0], $this->qty('2026-11-05'));
    }

    public function test_unavailable_transfer_metadata_falls_back_to_created_at(): void
    {
        $this->tx('TRF', 1, -3, 'Transfer #9999', '2026-10-20 09:00:00');

        $this->assertEquals([7.0, 0.0], $this->qty('2026-10-31'));
    }

    public function test_inventory_detail_replay_loads_transfers_and_dates_by_direction(): void
    {
        $this->transfer('2026-10-31 09:00:00', '2026-11-02 09:00:00', '2026-10-31 09:00:00');

        $result = (new InventoryDetailReportQueryService())->getDetail(
            new InventoryDetailReportFilterData(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30')),
            $this->setting->id
        );

        $row = $result['allRows'][0];
        // Opening reflects BUY 10 and outgoing dispatch of 3 (Oct 31).
        $this->assertEquals(7, $row['opening_row']['running_stock']);
        // Incoming leg is received Nov 2, inside the period.
        $this->assertEquals(10, $row['subtotal']['stock']);
        $this->assertEquals(3, $row['ledger_rows'][0]['mutation']);
    }

    // Scenarios where transaction created_at (Oct 20) differs from lifecycle timestamps,
    // so dispatch/receipt selection is distinguishable from the created_at fallback.
    private const CREATED = '2026-10-20 09:00:00';

    /** @return array<string, array{0: ?string, 1: ?string, 2: string, 3: float}> */
    public static function shapes(): array
    {
        return [
            // dispatched Nov 5, received Nov 8 (both after created_at)
            'full lifecycle, before dispatch' => ['2026-11-05 09:00:00', '2026-11-08 09:00:00', '2026-11-01', 10.0],
            'full lifecycle, between dispatch and receipt' => ['2026-11-05 09:00:00', '2026-11-08 09:00:00', '2026-11-06', 7.0],
            'full lifecycle, after receipt' => ['2026-11-05 09:00:00', '2026-11-08 09:00:00', '2026-11-09', 10.0],
            // received missing: incoming falls back to created_at (Oct 20), outgoing uses dispatch (Nov 5)
            'received missing, before dispatch' => ['2026-11-05 09:00:00', null, '2026-11-01', 13.0],
            'received missing, after dispatch' => ['2026-11-05 09:00:00', null, '2026-11-06', 10.0],
            // dispatched missing: outgoing falls back to created_at, incoming uses receipt (Nov 8)
            'dispatched missing, before receipt' => [null, '2026-11-08 09:00:00', '2026-11-06', 7.0],
            'dispatched missing, after receipt' => [null, '2026-11-08 09:00:00', '2026-11-09', 10.0],
        ];
    }

    /** @dataProvider shapes */
    public function test_inventory_summary_total_stock_follows_direction_specific_dates(?string $d, ?string $r, string $asOf, float $expected): void
    {
        $this->transfer($d, $r, self::CREATED);

        $result = (new InventorySummaryReportQueryService())->getSummary(
            new InventorySummaryReportFilterData(Carbon::parse($asOf)),
            $this->setting->id
        );

        $this->assertEquals($expected, $result['allRows']->first()['stock']);
    }

    /** @dataProvider shapes */
    public function test_warehouse_valuation_quantities_follow_direction_specific_dates(?string $d, ?string $r, string $asOf, float $expected): void
    {
        $this->transfer($d, $r, self::CREATED);

        $rows = (new WarehouseStockValuationReportQueryService())->build(
            new WarehouseStockValuationReportFilterData(asOfDate: $asOf, scopeSettingId: $this->setting->id)
        );

        $this->assertEquals($expected, (float) $rows->sum('qty'));
    }

    public function test_inventory_valuation_opening_and_period_follow_direction_specific_dates(): void
    {
        $this->transfer('2026-11-05 09:00:00', '2026-11-08 09:00:00', self::CREATED);

        // Period starts between dispatch and receipt: opening includes only the outgoing leg.
        $result = (new InventoryValuationReportQueryService())->getReport(
            new InventoryValuationReportFilterData(Carbon::parse('2026-11-06'), Carbon::parse('2026-11-30')),
            $this->setting->id
        );
        $row = $result['allRows']->first();

        $this->assertEquals(7, $row['opening_row']['running_stock']);
        $this->assertEquals(10, $row['subtotal']['stock']);
    }

    public function test_inventory_detail_distinguishes_dispatch_date_from_created_at(): void
    {
        $this->transfer('2026-11-05 09:00:00', '2026-11-08 09:00:00', self::CREATED);

        $row = (new InventoryDetailReportQueryService())->getDetail(
            new InventoryDetailReportFilterData(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30')),
            $this->setting->id
        )['allRows'][0];

        // Both legs dated inside the period by dispatch/receipt, not created_at (Oct 20).
        $this->assertEquals(10, $row['opening_row']['running_stock']);
        $this->assertCount(2, $row['ledger_rows']);
        $this->assertEquals([-3, 3], array_map(fn ($l) => (float) $l['mutation'], $row['ledger_rows']));
    }

    public function test_inventory_detail_missing_timestamps_use_created_at_for_that_leg_only(): void
    {
        $this->transfer(null, '2026-11-08 09:00:00', self::CREATED);

        $row = (new InventoryDetailReportQueryService())->getDetail(
            new InventoryDetailReportFilterData(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30')),
            $this->setting->id
        )['allRows'][0];

        // Outgoing falls back to Oct 20 (opening 7); incoming uses receipt Nov 8 (in period).
        $this->assertEquals(7, $row['opening_row']['running_stock']);
        $this->assertCount(1, $row['ledger_rows']);
        $this->assertEquals(3, $row['ledger_rows'][0]['mutation']);
    }

    public function test_stock_mutation_screen_and_export_agree_for_transfer(): void
    {
        session(['setting_id' => $this->setting->id]);
        $this->transfer('2026-11-05 09:00:00', null, self::CREATED);

        $filters = ['startDate' => '2026-11-01', 'endDate' => '2026-11-30', 'productId' => null,
            'locationId' => null, 'mutationType' => '', 'isGlobal' => false];

        $export = (new StockMutationReportExport($filters))->collection();

        $screen = new StockMutationReport();
        $screen->startDate = '2026-11-01';
        $screen->endDate = '2026-11-30';
        $screen->filterTriggered = true;
        $mutations = $screen->getMutationsProperty();

        // Only the outgoing leg (dispatched Nov 5) is in November; incoming falls back to Oct 20.
        $this->assertCount(1, $export);
        $this->assertEquals('05/11/2026', $export->first()['date']);
        $this->assertEquals(3, $export->first()['qty_out']);
        $this->assertCount(1, $mutations);
        $this->assertEquals('2026-11-05', $mutations->first()['date']);

        // Screen and export agree on everything except date formatting.
        $strip = fn ($rows) => $rows->map(fn ($r) => array_diff_key($r, ['date' => 1]))->all();
        $this->assertEquals($strip($export), $strip($mutations));
    }

    public function test_shared_replay_unavailable_transfer_metadata_uses_created_at(): void
    {
        // Transfer #9999 does not exist; outgoing leg must be dated by created_at (Oct 20).
        $this->tx('TRF', 1, -3, 'Transfer #9999', self::CREATED);

        $summary = new InventorySummaryReportQueryService();
        $stock = fn (string $asOf) => $summary->getSummary(
            new InventorySummaryReportFilterData(Carbon::parse($asOf)),
            $this->setting->id
        )['allRows']->first()['stock'];

        $this->assertEquals(10, $stock('2026-10-19'));
        $this->assertEquals(7, $stock('2026-10-20'));

        $row = (new InventoryDetailReportQueryService())->getDetail(
            new InventoryDetailReportFilterData(Carbon::parse('2026-10-20'), Carbon::parse('2026-10-31')),
            $this->setting->id
        )['allRows'][0];

        $this->assertEquals(10, $row['opening_row']['running_stock']);
        $this->assertEquals(-3, $row['ledger_rows'][0]['mutation']);
    }
}
