<?php

namespace Modules\Consignment\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Entities\ConsignmentBuyoutLine;
use Modules\Consignment\Entities\ConsignmentBuyoutPurchaseLineage;
use Modules\Consignment\Services\ConsignmentBuyoutPricingService;
use Modules\Consignment\Tests\Support\BuildsBuyoutFixtures;
use Modules\Consignment\Tests\Support\BuildsFinalizedBuyout;
use Modules\Product\Entities\ProductPrice;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

/**
 * Product-level (version 2) invoice terms: one commercial row per product across receipts and sold/unsold slices,
 * calculated once and allocated exactly back to the source slices, then one Purchase detail per product.
 */
class ConsignmentBuyoutProductPricingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsBuyoutFixtures;
    use BuildsFinalizedBuyout;

    private array $lines;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinalizationFixtures();

        // Receipt A at 100 (5 sold & attributed), receipt B at 120; 5 units physically at the consignment location.
        $a = $this->receipt($this->supplier, '10');
        $this->price($a, 100);
        $b = $this->receipt($this->supplier, '3');
        $this->price($b, 120);
        $src = $this->soldSource('5');
        $this->capacity()->setAttribution($src->id, [$a->id => '5'], null, 'attribute');
        $this->stock('8');
        $this->std($this->otherStd, 5);
        ProductPrice::create(['product_id' => $this->product->id, 'setting_id' => $this->setting->id, 'sale_price' => 1,
            'last_purchase_price' => 777, 'average_purchase_price' => 100]);

        $this->lines = [
            ['line_type' => 'SOLD_UNBILLED', 'consignment_receiving_detail_id' => $a->id, 'consignment_sold_source_id' => $src->id,
                'destination_location_id' => $this->dest1->id, 'base_quantity' => '2'],
            ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $b->id, 'destination_location_id' => $this->dest1->id,
                'base_quantity' => '3', 'buckets' => ['NON_TAXED' => '3']],
        ];
    }

    private function intent(array $product = [], array $extra = []): array
    {
        return array_merge(['version' => 2, 'products' => $product === [] ? [] : [$this->product->id => $product],
            'global_discount_type' => 'fixed', 'global_discount_value' => 0], $extra);
    }

    private function draft(array $intent, ?array $lines = null): ConsignmentBuyout
    {
        return $this->life->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', $lines ?? $this->lines, $this->user,
            ['invoice_metadata' => ['invoice_date' => '2026-11-10'], 'pricing_intent' => $intent]);
    }

    private function assertDraftRejected(array $intent, string $fragment): void
    {
        $before = ConsignmentBuyout::count();
        try {
            $this->draft($intent);
            $this->fail('draft accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());
        }
        $this->assertSame($before, ConsignmentBuyout::count(), 'no partial draft');
        $this->assertSame(0, ConsignmentBuyoutLine::count());
    }

    /** @test */
    public function differing_receipt_prices_require_one_explicit_product_price(): void
    {
        $this->assertDraftRejected($this->intent(), 'harga penerimaan berbeda');
        $this->assertDraftRejected($this->intent(['discount_value' => 5]), 'harga penerimaan berbeda');
        $this->assertDraftRejected($this->intent([], ['products' => [$this->product->id => ['unit_price' => 110], 999999 => ['unit_price' => 1]]]), 'not part of the selected goods');
        $this->assertDraftRejected($this->intent(['unit_price' => 110], ['rows' => [1 => ['unit_price' => 5]]]), 'Source-level price overrides');
        $this->assertDraftRejected($this->intent(['unit_price' => -1]), 'tidak valid');
        $this->assertDraftRejected($this->intent(['unit_price' => 110], ['global_discount_value' => 9999]), 'cannot exceed');
    }

    /** @test */
    public function one_product_row_combines_sold_and_unsold_receipts_with_one_price(): void
    {
        $b = $this->draft($this->intent(['unit_price' => 110]));
        $r = (new ConsignmentBuyoutPricingService())->calculate($b);

        $this->assertTrue($r['valid'], implode(' ', $r['blockers']));
        $this->assertTrue($r['product_intent']);
        $this->assertCount(1, $r['products']);
        $row = $r['products'][0];
        $this->assertEquals(5.0, $row['quantity']);
        $this->assertFalse($row['prices_agree']);
        $this->assertEquals([100.0, 120.0], $row['original']['unit_prices']);
        $this->assertEquals(550.0, $row['accepted']['total']);
        $this->assertCount(2, $row['line_ids']);
        $bySlice = collect($r['slices'])->keyBy('line_type');
        $this->assertEquals([220.0, 330.0], [$bySlice['SOLD_UNBILLED']['accepted']['total'], $bySlice['UNSOLD_BUYOUT']['accepted']['total']]);
        $this->assertEquals([110.0, 110.0], [$bySlice['SOLD_UNBILLED']['accepted']['unit_price'], $bySlice['UNSOLD_BUYOUT']['accepted']['unit_price']]);
        // Original receipt evidence is retained per slice.
        $this->assertEquals([200.0, 360.0], [$bySlice['SOLD_UNBILLED']['original']['total'], $bySlice['UNSOLD_BUYOUT']['original']['total']]);
        // Only the unsold slice carries inventory cost.
        $this->assertNull($bySlice['SOLD_UNBILLED']['inventory_cost']);
        $this->assertEquals(330.0, $bySlice['UNSOLD_BUYOUT']['inventory_cost']);
        $this->assertEquals(550.0, $r['totals']['total_amount']);
    }

    /** @test */
    public function row_total_override_and_fractional_quantities_reconcile_to_the_cent(): void
    {
        $this->lines[1]['base_quantity'] = '2.5';
        $this->lines[1]['buckets'] = ['NON_TAXED' => '2.5'];
        $b = $this->draft($this->intent(['unit_price' => 50, 'row_total_override' => '1000.01'], ['global_discount_type' => 'percentage', 'global_discount_value' => 10]));
        $r = (new ConsignmentBuyoutPricingService())->calculate($b);

        $this->assertTrue($r['valid'], implode(' ', $r['blockers']));
        $row = $r['products'][0];
        $this->assertEquals(4.5, $row['quantity']);
        $this->assertEquals(1000.01, $row['accepted']['total']);
        $this->assertEqualsWithDelta(1000.01 / 4.5, $row['accepted']['unit_price'], 0.000001);
        $totals = array_map(fn ($s) => (int) round($s['accepted']['total'] * 100), $r['slices']);
        $this->assertSame(100001, array_sum($totals));
        $this->assertEquals(900.01, $r['totals']['total_amount']);
        $this->assertEquals(round(array_sum(array_column($r['slices'], 'payable')), 2), $r['totals']['total_amount']);
        $this->assertEquals(100.0, $r['totals']['global_discount_amount']);
    }

    /** @test */
    public function explicit_zero_is_a_deliberate_price_and_agreeing_prices_need_no_input(): void
    {
        $zero = (new ConsignmentBuyoutPricingService())->calculate($this->draft($this->intent(['unit_price' => '0'])));
        $this->assertTrue($zero['valid'], implode(' ', $zero['blockers']));
        $this->assertEquals(0.0, $zero['totals']['total_amount']);

        // Same receipt price for every slice: no explicit price is needed, the receipt price is the default.
        DB::table('consignment_receiving_details')->update(['unit_dpp' => 100, 'unit_cost' => 100]);
        $ok = (new ConsignmentBuyoutPricingService())->calculate($this->draft($this->intent()));
        $this->assertTrue($ok['valid'], implode(' ', $ok['blockers']));
        $this->assertTrue($ok['products'][0]['prices_agree']);
        $this->assertEquals(100.0, $ok['products'][0]['default_unit_price']);
        $this->assertEquals(500.0, $ok['totals']['total_amount']);
    }

    /** @test */
    public function non_pkp_neutralizes_forged_tax_and_pkp_requires_a_common_tax_choice(): void
    {
        $tax = Tax::create(['name' => 'PPN', 'value' => 11, 'is_active' => true]);
        $r = (new ConsignmentBuyoutPricingService())->calculate($this->draft($this->intent(['unit_price' => 110, 'tax_id' => $tax->id])));
        $this->assertTrue($r['valid']);
        $this->assertTrue($r['neutralized_tax_inputs']);
        $this->assertEquals(0.0, $r['totals']['tax_amount']);
        $this->assertFalse($r['is_tax_included']);

        // PKP with differing receipt tax evidence (one taxed receipt, one untaxed): a common tax must be chosen.
        $this->setting->update(['is_pkp' => true]);
        DB::table('consignment_receiving_details')->where('id', $this->lines[0]['consignment_receiving_detail_id'])->update(['tax_id' => $tax->id, 'tax_rate' => 11, 'tax_amount' => 110]);
        $svc = new ConsignmentBuyoutPricingService();
        $lines = collect($this->lines)->values()->map(fn ($l, $i) => new ConsignmentBuyoutLine($l + ['id' => $i + 1,
            'product_id' => $this->product->id]))->each(fn ($l, $i) => $l->id = $i + 1);
        $missing = $svc->calculateLines($this->setting->id, $lines, $this->intent(['unit_price' => 111]));
        $this->assertFalse($missing['valid']);
        $this->assertTrue($missing['products'][0]['needs_tax']);
        $this->assertEquals(0.0, $missing['products'][0]['default_tax_rate'], 'disagreeing receipt tax has no default rate');
        $agreeing = $svc->calculateLines($this->setting->id, $lines->take(1), $this->intent(['unit_price' => 111, 'tax_id' => $tax->id]));
        $this->assertEquals(11.0, $agreeing['products'][0]['default_tax_rate'], 'receipt rate, not the chosen invoice tax');

        $taxed = $svc->calculateLines($this->setting->id, $lines, $this->intent(['unit_price' => 111, 'tax_id' => $tax->id]));
        $this->assertTrue($taxed['valid'], implode(' ', $taxed['blockers']));
        $this->assertTrue($taxed['is_tax_included']);
        $this->assertEquals(555.0, $taxed['totals']['total_amount']);
        $this->assertEquals(55.0, $taxed['totals']['tax_amount']);
        $this->assertEquals(55.0, round(array_sum(array_map(fn ($s) => $s['accepted']['tax_amount'], $taxed['slices'])), 2));

        $none = $svc->calculateLines($this->setting->id, $lines, $this->intent(['unit_price' => 111, 'tax_id' => ConsignmentBuyoutPricingService::NO_TAX]));
        $this->assertTrue($none['valid'], implode(' ', $none['blockers']));
        $this->assertEquals(0.0, $none['totals']['tax_amount']);
        $this->assertEquals(555.0, $none['totals']['total_amount']);
    }

    /** @test */
    public function finalization_creates_one_detail_per_product_with_lossless_lineage(): void
    {
        $b = $this->draft($this->intent(['row_total_override' => '550.01'], ['global_discount_value' => 50]));
        $b = $this->life->approve($this->life->submit($b, $this->user), $this->user);
        $purchase = $this->fin->finalize($b, $this->user);

        $this->assertSame(1, $purchase->purchaseDetails()->count());
        $detail = $purchase->purchaseDetails()->sole();
        $this->assertEquals(5.0, (float) $detail->quantity);
        $this->assertEquals(550.01, (float) $detail->sub_total);
        $this->assertEquals(round(550.01 / 5, 6), (float) $detail->unit_price);
        $this->assertEquals(500.01, (float) $purchase->total_amount);

        $links = ConsignmentBuyoutPurchaseLineage::where('purchase_id', $purchase->id)->orderBy('id')->get();
        $this->assertCount(2, $links);
        $this->assertSame([$detail->id], $links->pluck('purchase_detail_id')->unique()->values()->all());
        $this->assertEquals(500.01, round($links->sum('accepted_amount'), 2));
        $this->assertEquals([200.0, 360.0], $links->pluck('original_amount')->map(fn ($v) => (float) $v)->all());
        $this->assertEquals(550.01, round($links->sum(fn ($l) => $l->allocation_snapshot['accepted_total']), 2));

        // Only the unsold accepted amount enters the acquisition cost.
        $unsold = $links->firstWhere(fn ($l) => $l->allocation_snapshot['line_type'] === 'UNSOLD_BUYOUT');
        $event = DB::table('consignment_buyout_cost_events')->sole();
        $this->assertEquals((float) $unsold->inventory_cost_amount, (float) $event->acquired_cost_total);
        $this->assertEquals(3.0, (float) $event->acquired_quantity);

        // Idempotent retry.
        $this->assertSame($purchase->id, $this->fin->finalize($b->fresh(), $this->user)->id);
        $this->assertSame(1, DB::table('purchase_details')->count());
    }

    /** @test */
    public function cent_allocation_is_exact_for_large_supported_amounts(): void
    {
        // 9,999,999,999,999.99 split over 999,999.999 + 0.001 units (milli weights) would overflow native integers.
        $cents = 999999999999999;
        $shares = ConsignmentBuyoutPricingService::splitCents($cents, [1 => 999999999, 2 => 1, 3 => 999999999]);
        $this->assertSame($cents, array_sum($shares));
        $this->assertTrue(array_reduce($shares, fn ($ok, $v) => $ok && is_int($v) && $v >= 0, true));
        $this->assertSame([1 => 2, 2 => 0, 3 => 1], ConsignmentBuyoutPricingService::splitCents(3, [1 => 1, 2 => 0, 3 => 1]) + [], 'ties go to the earliest key');
    }

    /** @test */
    public function legacy_line_terms_project_to_product_terms_or_conflicts(): void
    {
        $b = $this->life->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', $this->lines, $this->user,
            ['invoice_metadata' => ['invoice_date' => '2026-11-10']]);
        [$l1, $l2] = $b->lines()->orderBy('id')->pluck('id')->all();
        $svc = new ConsignmentBuyoutPricingService();

        $b->update(['pricing_intent' => ['rows' => [$l1 => ['unit_price' => 110], $l2 => ['unit_price' => '110']], 'global_discount_value' => 5]]);
        $p = $svc->projectLegacyIntent($b->fresh());
        $this->assertSame([], $p['conflicts']);
        $this->assertSame(['unit_price' => '110'], $p['products'][$this->product->id]);
        $this->assertEquals(5.0, $p['global_discount_value']);

        $b->update(['pricing_intent' => ['rows' => [$l1 => ['unit_price' => 110], $l2 => ['unit_price' => 120]]]]);
        $this->assertSame([$this->product->id], $svc->projectLegacyIntent($b->fresh())['conflicts']);

        $b->update(['pricing_intent' => ['rows' => [$l1 => ['unit_price' => 110]]]]);
        $this->assertSame([$this->product->id], $svc->projectLegacyIntent($b->fresh())['conflicts'], 'saved term on one line only');
    }
}
