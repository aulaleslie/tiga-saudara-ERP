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

    /** Second business sharing the product, with its own owned stock at a different cost (4 @ 250). */
    private function secondBusiness(): \Modules\Setting\Entities\Setting
    {
        $s2 = $this->setting->replicate();
        $s2->company_name = 'Second Business';
        $s2->document_prefix = 'SB2';
        $s2->save();
        $loc = \Modules\Setting\Entities\Location::create(['setting_id' => $s2->id, 'name' => 'S2 Store', 'is_consignment' => false]);
        \Modules\Product\Entities\ProductPrice::create(['product_id' => $this->product->id, 'setting_id' => $s2->id, 'sale_price' => 1,
            'last_purchase_price' => 250, 'average_purchase_price' => 250]);
        $t = Transaction::create(['product_id' => $this->product->id, 'setting_id' => $s2->id, 'type' => 'BUY', 'quantity' => 4,
            'current_quantity' => 4, 'broken_quantity' => 0, 'location_id' => $loc->id, 'previous_quantity' => 0, 'after_quantity' => 4,
            'previous_quantity_at_location' => 0, 'after_quantity_at_location' => 4, 'quantity_tax' => 0, 'quantity_non_tax' => 4,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'reason' => 'Penerimaan S2']);
        \DB::table('transactions')->where('id', $t->id)->update(['created_at' => '2026-11-02 08:00:00', 'updated_at' => '2026-11-02 08:00:00']);

        return $s2;
    }

    private function valuationReport(Carbon $from, Carbon $to, array $settingIds): array
    {
        $f = new InventoryValuationReportFilterData();
        $f->tanggalAwal = $from;
        $f->tanggalAkhir = $to;
        $f->productIds = [$this->product->id];

        return (new InventoryValuationReportQueryService())->getReport($f, $settingIds, 10, 1) + ['filters' => $f];
    }

    private function summaryReport(Carbon $asOf, array $settingIds): array
    {
        $filters = new InventorySummaryReportFilterData($asOf, null, [], 'any', [$this->product->id]);

        return (new InventorySummaryReportQueryService())->getSummary($filters, $settingIds, 50, 1) + ['filters' => $filters];
    }

    /** @test integration with main's per-business reports: buyout effects stay inside the business that owns them */
    public function multi_business_views_apply_buyout_effects_per_business_with_different_costs(): void
    {
        $this->ownedBuy(Carbon::parse('2026-11-01 08:00:00'));
        $s2 = $this->secondBusiness();
        [$b] = $this->mixed(null, 120.0);
        \DB::table('product_prices')->where('setting_id', $this->setting->id)->update(['last_purchase_price' => 100]);
        Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00'));
        $this->fin->finalize($b->fresh(), $this->user);
        Carbon::setTestNow(Carbon::parse('2026-11-20 10:00:00'));
        (new ConsignmentBuyoutReversalService())->reverse($b->fresh(), $this->user, 'undo');
        Carbon::setTestNow();

        $ids = [$this->setting->id, $s2->id];
        $s1 = $this->setting->id;
        $expect = [
            // as-of => [S1 stock, S1 avg, S1 value]; S2 is always 4 @ 250 = 1000
            '2026-11-12 00:00:00' => [10.0, 100.0, 1000.0],   // before acquisition
            '2026-11-16 00:00:00' => [13.0, 106.0, 1378.0],   // after acquisition
            '2026-11-18 00:00:00' => [13.0, 106.0, 1378.0],   // historical date between acquisition and reversal
            '2026-11-21 00:00:00' => [10.0, 100.0, 1000.0],   // after reversal
        ];
        foreach ($expect as $asOf => [$st, $avg, $val]) {
            $at = Carbon::parse($asOf);

            $v = $this->valuationReport($at->copy()->subDays(30), $at, $ids);
            $row = $v['allRows']->first();
            $this->assertEquals([$st, $avg, $val], [(float) $row['businesses'][$s1]['ending_stock'], (float) $row['businesses'][$s1]['ending_avg'],
                (float) $row['businesses'][$s1]['ending_value']], "valuation S1 at {$asOf}");
            $this->assertEquals([4.0, 250.0, 1000.0], [(float) $row['businesses'][$s2->id]['ending_stock'], (float) $row['businesses'][$s2->id]['ending_avg'],
                (float) $row['businesses'][$s2->id]['ending_value']], "valuation S2 at {$asOf}");
            $this->assertEquals([$st + 4, $val + 1000], [(float) $row['ending_stock'], (float) $row['ending_value']], "valuation total at {$asOf}");
            $this->assertEquals($val, (float) $v['businessTotals'][$s1]['value']);
            $this->assertEquals(1000.0, (float) $v['businessTotals'][$s2->id]['value']);
            $this->assertEquals($val + 1000, (float) $v['totalValue']);

            $single = $this->valuationReport($at->copy()->subDays(30), $at, [$s1])['allRows']->first();
            $this->assertEquals([$st, $avg, $val], [(float) $single['ending_stock'], (float) $single['ending_avg'], (float) $single['ending_value']], "single S1 at {$asOf}");

            $sm = $this->summaryReport($at, $ids);
            $srow = $sm['allRows']->first();
            $this->assertEquals([$st, $avg, $val], [(float) $srow['businesses'][$s1]['stock'], (float) $srow['businesses'][$s1]['average_cost'],
                (float) $srow['businesses'][$s1]['value']], "summary S1 at {$asOf}");
            $this->assertEquals([4.0, 250.0, 1000.0], [(float) $srow['businesses'][$s2->id]['stock'], (float) $srow['businesses'][$s2->id]['average_cost'],
                (float) $srow['businesses'][$s2->id]['value']], "summary S2 at {$asOf}");
            $this->assertEquals([$st + 4, $val + 1000], [(float) $srow['total_stock'], (float) $srow['total_value']]);
            $this->assertEquals($val + 1000, (float) $sm['totalValue']);
            $this->assertEquals($val, (float) $sm['businessTotals'][$s1]['value']);

            // Exports carry the same per-business figures.
            $export = new \App\Exports\InventorySummaryReportExport($sm['allRows'], $sm['filters'], true, $ids);
            $mapped = $export->map($srow);
            $this->assertContains((float) $val, $mapped, "summary export S1 value at {$asOf}");
            $this->assertContains(1000.0, $mapped);
        }
    }

    /** @test owned period movement counts only the owned leg; ledger and export use explicit buyout labels */
    public function valuation_ledger_labels_buyout_events_and_counts_no_phantom_receiving(): void
    {
        $this->ownedBuy(Carbon::parse('2026-11-01 08:00:00'));
        [$b] = $this->mixed(null, 120.0);
        \DB::table('product_prices')->update(['last_purchase_price' => 100]);
        Carbon::setTestNow(Carbon::parse('2026-11-15 10:00:00'));
        $this->fin->finalize($b->fresh(), $this->user);
        Carbon::setTestNow(Carbon::parse('2026-11-20 10:00:00'));
        (new ConsignmentBuyoutReversalService())->reverse($b->fresh(), $this->user, 'undo');
        Carbon::setTestNow();

        $acq = $this->valuationReport(Carbon::parse('2026-11-14 00:00:00'), Carbon::parse('2026-11-16 00:00:00'), [$this->setting->id]);
        $row = $acq['allRows']->first();
        $this->assertEquals([10.0, 3.0, 0.0, 13.0], [(float) $row['opening_stock'], (float) $row['period_stock_in'], (float) $row['period_stock_out'], (float) $row['ending_stock']],
            'only the owned acquisition leg is an owned inflow; the consignment-side leg is not an outflow');
        $labels = array_column($row['ledger_rows'], 'type_label');
        $this->assertContains('Pembelian Putus Konsinyasi', $labels);
        $this->assertNotContains('Mutasi Stok', $labels);

        $export = (new \App\Exports\InventoryValuationReportExport($acq['allRows'], (float) $acq['totalValue'], $acq['filters'], false, [$this->setting->id]))->array();
        $this->assertContains('Pembelian Putus Konsinyasi', array_merge(...array_map(fn ($r) => array_values((array) $r), $export)));

        $rev = $this->valuationReport(Carbon::parse('2026-11-19 00:00:00'), Carbon::parse('2026-11-21 00:00:00'), [$this->setting->id])['allRows']->first();
        $this->assertEquals([13.0, 0.0, 3.0, 10.0], [(float) $rev['opening_stock'], (float) $rev['period_stock_in'], (float) $rev['period_stock_out'], (float) $rev['ending_stock']]);
        $this->assertContains('Pembatalan Pembelian Putus Konsinyasi', array_column($rev['ledger_rows'], 'type_label'));
    }

    /** @test */
    public function buyout_types_have_explicit_labels_in_every_report_context(): void
    {
        $label = \App\Support\Reports\InventoryTransactionTypeLabel::class;
        foreach ([null, $label::CONTEXT_MUTATION, $label::CONTEXT_DETAIL, $label::CONTEXT_VALUATION] as $ctx) {
            $this->assertSame('Pembelian Putus Konsinyasi', $label::label('CONSIGNMENT_BUYOUT', 3.0, $ctx));
            $this->assertSame('Pembatalan Pembelian Putus Konsinyasi', $label::label('consignment_buyout_reversal', -3.0, $ctx));
        }
    }
}
