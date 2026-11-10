<?php

namespace Modules\Consignment\Tests\Unit;

use App\Services\Reports\InventorySummaryReportFilterData;
use App\Services\Reports\InventorySummaryReportQueryService;
use App\Services\Reports\InventoryValuationReportFilterData;
use App\Services\Reports\InventoryValuationReportQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Consignment\Services\ConsignmentBuyoutReversalService;
use Modules\Consignment\Tests\Support\BuildsBuyoutFixtures;
use Modules\Consignment\Tests\Support\BuildsFinalizedBuyout;
use Modules\Product\Entities\Transaction;
use Tests\TestCase;

/**
 * Owned quantity/valuation projections with acquisition and reversal events: no phantom receiving, no back-dating to
 * the invoice date, owned valuation follows the recorded carrying average.
 */
class ConsignmentBuyoutInventoryProjectionTest extends TestCase
{
    use RefreshDatabase;
    use BuildsBuyoutFixtures;
    use BuildsFinalizedBuyout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinalizationFixtures();
        $this->product->forceFill(['stock_managed' => true])->save();
    }

    private function ownedBuy(Carbon $at): void
    {
        \DB::table('product_prices')->where('product_id', $this->product->id)->update(['last_purchase_price' => 100]);
        $t = Transaction::create(['product_id' => $this->product->id, 'setting_id' => $this->setting->id, 'type' => 'BUY', 'quantity' => 10,
            'current_quantity' => 10, 'broken_quantity' => 0, 'location_id' => $this->otherStd->id, 'previous_quantity' => 0, 'after_quantity' => 10,
            'previous_quantity_at_location' => 0, 'after_quantity_at_location' => 10, 'quantity_tax' => 0, 'quantity_non_tax' => 10,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'reason' => 'Penerimaan awal']);
        \DB::table('transactions')->where('id', $t->id)->update(['created_at' => $at, 'updated_at' => $at]);
    }

    private function valuation(Carbon $asOf): array
    {
        $f = new InventoryValuationReportFilterData();
        $f->tanggalAwal = $asOf->copy()->subDays(30);
        $f->tanggalAkhir = $asOf;
        $f->productIds = [$this->product->id];
        $row = (new InventoryValuationReportQueryService())->getReport($f, $this->setting->id, 10, 1)['allRows']->first();

        return [(float) $row['ending_stock'], (float) $row['ending_avg'], (float) $row['ending_value']];
    }

    /** @return array{0: float, 1: float, 2: float} stock, average, value from the summary report */
    private function summary(Carbon $asOf): array
    {
        $filters = new InventorySummaryReportFilterData($asOf, null, [], 'any', [$this->product->id]);
        $row = (new InventorySummaryReportQueryService())->getSummary($filters, $this->setting->id, 50, 1)['allRows']->first();

        return [(float) $row['stock'], (float) $row['average_cost'], (float) $row['value']];
    }

    /** @test */
    public function owned_quantity_and_valuation_follow_acquisition_and_reversal_without_back_dating_or_phantom_receiving(): void
    {
        $this->ownedBuy(Carbon::parse('2026-11-01 08:00:00'));
        [$b] = $this->mixed(null, 120.0);                    // invoice_date 2026-11-10 (set by meta())
        \DB::table('product_prices')->update(['last_purchase_price' => 100]);

        Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00'));
        $this->fin->finalize($b->fresh(), $this->user);

        // Before the finalization instant (even after the invoice date) the 3 units are still non-owned consignment.
        [$stock, $avg, $value] = $this->valuation(Carbon::parse('2026-11-12 23:59:59'));
        $this->assertEquals([10.0, 100.0, 1000.0], [$stock, $avg, $value], 'not back-dated to the invoice date');

        // After it: 3 more OWNED units (the consignment-side leg is ignored) valued at the recorded carrying average 106.
        [$stock, $avg, $value] = $this->valuation(Carbon::parse('2026-11-16 00:00:00'));
        $this->assertEquals([13.0, 106.0, 1378.0], [$stock, $avg, $value]);

        // The same transactions leave the business total (consignment + owned) untouched: no phantom receiving.
        $this->assertEquals(10, \Modules\Product\Entities\ProductStock::where('product_id', $this->product->id)->sum('quantity'));
        $this->assertSame(2, Transaction::where('type', 'CONSIGNMENT_BUYOUT')->count());

        // Reversal: owned stock and the average go back.
        Carbon::setTestNow(Carbon::parse('2026-11-20 10:00:00'));
        (new ConsignmentBuyoutReversalService())->reverse($b->fresh(), $this->user, 'undo');
        [$stock, $avg, $value] = $this->valuation(Carbon::parse('2026-11-21 00:00:00'));
        $this->assertEquals([10.0, 100.0, 1000.0], [$stock, $avg, $value]);
        // ...while the period in between still shows the acquisition (as-of semantics).
        $this->assertEquals([13.0, 106.0, 1378.0], $this->valuation(Carbon::parse('2026-11-18 00:00:00')));
        Carbon::setTestNow();
    }

    /** @test */
    public function the_summary_report_uses_the_same_ownership_rules(): void
    {
        $this->ownedBuy(Carbon::parse('2026-11-01 08:00:00'));
        [$b] = $this->mixed(null, 120.0);
        \DB::table('product_prices')->update(['last_purchase_price' => 100]);
        Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00'));
        $this->fin->finalize($b->fresh(), $this->user);

        $this->assertEquals([13.0, 106.0, 1378.0], $this->summary(Carbon::parse('2026-11-16 00:00:00')));
        $this->assertEquals([10.0, 100.0, 1000.0], $this->summary(Carbon::parse('2026-11-12 00:00:00')));
        Carbon::setTestNow();
    }

    /** @test */
    public function sold_only_documents_add_no_owned_stock_or_cost_event(): void
    {
        $this->ownedBuy(Carbon::parse('2026-11-01 08:00:00'));
        $r = $this->receipt($this->supplier, '10');
        $this->price($r, 100);
        $src = $this->soldSource('5');
        $this->capacity()->setAttribution($src->id, [$r->id => '5'], null, 'a');
        \Modules\Product\Entities\ProductPrice::create(['product_id' => $this->product->id, 'setting_id' => $this->setting->id, 'sale_price' => 1, 'last_purchase_price' => 100, 'average_purchase_price' => 100]);
        $b = $this->life->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', [[
            'line_type' => 'SOLD_UNBILLED', 'consignment_receiving_detail_id' => $r->id, 'consignment_sold_source_id' => $src->id,
            'destination_location_id' => $this->dest1->id, 'base_quantity' => '2']], $this->user, $this->meta());
        $b = $this->life->approve($this->life->submit($b, $this->user), $this->user);
        Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00'));
        $this->fin->finalize($b, $this->user);

        $this->assertEquals([10.0, 100.0, 1000.0], $this->valuation(Carbon::parse('2026-11-16 00:00:00')));
        $this->assertSame(0, Transaction::where('type', 'CONSIGNMENT_BUYOUT')->count());
        Carbon::setTestNow();
    }
}
