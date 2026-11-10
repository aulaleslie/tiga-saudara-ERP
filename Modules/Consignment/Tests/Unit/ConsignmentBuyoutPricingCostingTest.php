<?php

namespace Modules\Consignment\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Entities\ConsignmentBuyoutLine;
use Modules\Consignment\Entities\ConsignmentReceivingDetail;
use Modules\Consignment\Services\ConsignmentBuyoutCostingService;
use Modules\Consignment\Services\ConsignmentBuyoutPricingService;
use Modules\Consignment\Tests\Support\BuildsBuyoutFixtures;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

class ConsignmentBuyoutPricingCostingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsBuyoutFixtures;

    private ConsignmentBuyoutPricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBuyoutFixtures();
        $this->pricing = new ConsignmentBuyoutPricingService();
    }

    private function priced(ConsignmentReceivingDetail $r, float $dpp, float $cost = 0.0, float $taxAmount = 0.0, ?int $taxId = null, float $taxRate = 0.0): ConsignmentReceivingDetail
    {
        \DB::table('consignment_receiving_details')->where('id', $r->id)->update([
            'unit_dpp' => $dpp, 'unit_cost' => $cost ?: $dpp, 'tax_amount' => $taxAmount, 'tax_id' => $taxId, 'tax_rate' => $taxRate,
        ]);

        return $r->fresh();
    }

    private function doc(array $intent = []): ConsignmentBuyout
    {
        $b = $this->buyout($this->supplier);
        $b->update(['pricing_intent' => $intent ?: null]);

        return $b->fresh();
    }

    private function sold(ConsignmentBuyout $b, ConsignmentReceivingDetail $r, string $qty)
    {
        return $this->line($b, $r, 'SOLD_UNBILLED', $qty, $this->soldSource($qty));
    }

    // ---------------------------------------------------------------- 5.4 carrying-value replacement

    /** @test */
    public function carrying_value_examples_match_the_design(): void
    {
        $r = ConsignmentBuyoutCostingService::replace(10, 100, 4, 480);
        $this->assertSame(108.0, $r['average_after']);

        $r = ConsignmentBuyoutCostingService::replace(10, 100, 4, 432);
        $this->assertSame(103.2, $r['average_after']);

        $r = ConsignmentBuyoutCostingService::replace(5, 100, 1.25, 150);
        $this->assertSame(105.0, $r['average_after']);
        $this->assertSame(100.0, $r['average_before']);
    }

    /** @test */
    public function carrying_value_rejects_invalid_inputs_instead_of_clamping_or_seeding(): void
    {
        foreach ([
            'missing average' => [10, null, 4, 100],
            'negative average' => [10, -1, 4, 100],
            'zero quantity' => [0, 100, 0.5, 100],
            'acquired exceeds physical' => [3, 100, 4, 100],
            'zero acquired' => [10, 100, 0, 0],
            'negative cost' => [10, 100, 4, -1],
        ] as $label => $args) {
            try {
                ConsignmentBuyoutCostingService::replace(...$args);
                $this->fail("accepted: {$label}");
            } catch (\DomainException $e) {
                $this->assertTrue(true);
            }
        }
        // A legitimate zero cost is allowed.
        $this->assertSame(60.0, ConsignmentBuyoutCostingService::replace(10, 100, 4, 0)['average_after']);
    }

    /** @test */
    public function plan_aggregates_receipts_and_destinations_per_product_and_ignores_other_businesses(): void
    {
        $r1 = $this->priced($this->receipt($this->supplier, '6'), 100);
        $r2 = $this->priced($this->receipt($this->supplier, '6'), 100);
        $b = $this->doc();
        $this->line($b, $r1, 'UNSOLD_BUYOUT', '2');
        ConsignmentBuyoutLine::create(['consignment_buyout_id' => $b->id, 'line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $r2->id,
            'product_id' => $this->product->id, 'source_location_id' => $this->location->id, 'destination_location_id' => null,
            'base_quantity' => '2', 'original_snapshot' => []]);
        // Q = 10 in this business (two locations), plus 99 in another business that must be ignored.
        $other = Location::create(['setting_id' => $this->setting->id, 'name' => 'Rack C', 'is_consignment' => false]);
        $this->stock('6');
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $other->id, 'quantity' => 4, 'quantity_tax' => 0, 'quantity_non_tax' => 4,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0]);
        $otherSetting = \Modules\Setting\Entities\Setting::create(['company_name' => 'Other', 'company_email' => 'x@y.z', 'company_phone' => '1',
            'default_currency_id' => $this->setting->default_currency_id, 'default_currency_position' => 'prefix', 'notification_email' => 'x@y.z',
            'footer_text' => 'f', 'company_address' => 'a', 'is_pkp' => false]);
        $foreign = Location::create(['setting_id' => $otherSetting->id, 'name' => 'Foreign']);
        ProductStock::create(['product_id' => $this->product->id, 'location_id' => $foreign->id, 'quantity' => 99, 'quantity_tax' => 0, 'quantity_non_tax' => 99,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0]);
        ProductPrice::create(['product_id' => $this->product->id, 'setting_id' => $this->setting->id, 'sale_price' => 1, 'last_purchase_price' => 777, 'average_purchase_price' => 100]);

        $slices = $this->pricing->calculate($b->fresh())['slices'];
        \DB::beginTransaction();
        $plan = (new ConsignmentBuyoutCostingService())->plan($b, array_map(fn ($s) => array_merge($s, ['inventory_cost' => 240.0]), $slices));
        \DB::rollBack();

        $p = $plan[$this->product->id];
        $this->assertNull($p['blocker']);
        $this->assertEquals(10.0, $p['total_quantity']);
        $this->assertEquals(4.0, $p['acquired_quantity']);
        $this->assertEquals(480.0, $p['acquired_cost_total']);
        $this->assertSame(108.0, $p['average_after']);
        $this->assertEquals(777, (float) ProductPrice::first()->last_purchase_price, 'last purchase price is never touched');
    }

    /** @test */
    public function plan_blocks_a_missing_average_and_ignores_sold_only_documents(): void
    {
        $r = $this->priced($this->receipt($this->supplier, '6'), 100);
        $this->stock('6');
        $b = $this->doc();
        $this->line($b, $r, 'UNSOLD_BUYOUT', '2');

        \DB::beginTransaction();
        $svc = new ConsignmentBuyoutCostingService();
        $plan = $svc->plan($b, [['line_type' => 'UNSOLD_BUYOUT', 'product_id' => $this->product->id, 'quantity' => 2, 'inventory_cost' => 200.0]]);
        $this->assertNotNull($plan[$this->product->id]['blocker']);
        $soldOnly = $svc->plan($b, [['line_type' => 'SOLD_UNBILLED', 'product_id' => $this->product->id, 'quantity' => 2, 'inventory_cost' => null]]);
        \DB::rollBack();
        $this->assertSame([], $soldOnly, 'sold-only: no ownership cost event, average and quantity unchanged');
    }

    // ---------------------------------------------------------------- 5.1 / 5.3 pricing

    /** @test */
    public function mixed_invoice_matches_the_design_example_and_reconciles_exactly(): void
    {
        $r = $this->priced($this->receipt($this->supplier, '20'), 100);
        $rUnsold = $this->priced($this->receipt($this->supplier, '20'), 120);
        $b = $this->doc(['global_discount_type' => 'fixed', 'global_discount_value' => 68]);
        $soldLine = $this->sold($b, $r, '2');                 // 2 x 100 = 200
        $unsoldLine = $this->line($b, $rUnsold, 'UNSOLD_BUYOUT', '4'); // 4 x 120 = 480

        $res = $this->pricing->calculate($b->fresh());
        $this->assertTrue($res['valid'], implode(' ', $res['blockers']));
        $by = collect($res['slices'])->keyBy('line_id');
        $this->assertSame(20.0, $by[$soldLine->id]['document_discount']);
        $this->assertSame(48.0, $by[$unsoldLine->id]['document_discount']);
        $this->assertSame(432.0, $by[$unsoldLine->id]['inventory_cost']);
        $this->assertNull($by[$soldLine->id]['inventory_cost'], 'sold slices carry no inventory cost');
        $this->assertSame(612.0, $res['totals']['total_amount']);
        $this->assertSame(612.0, round($by->sum('payable'), 2));
        $this->assertSame(680.0, $res['totals']['original_total']);
    }

    /** @test */
    public function preview_and_accepted_terms_agree_and_sold_and_unsold_slices_stay_separate_per_receipt(): void
    {
        $a = $this->priced($this->receipt($this->supplier, '10'), 50);
        $c = $this->priced($this->receipt($this->supplier, '10'), 70);
        $b = $this->doc(['global_discount_type' => 'percentage', 'global_discount_value' => 10]);
        $this->line($b, $a, 'UNSOLD_BUYOUT', '3');
        $this->line($b, $c, 'UNSOLD_BUYOUT', '2');
        $this->sold($b, $a, '1');
        $b->update(['status' => ConsignmentBuyout::STATUS_APPROVED]);

        $preview = $this->pricing->calculate($b->fresh());
        $accepted = $this->pricing->accept($b->fresh());
        $this->assertSame($preview['totals'], $accepted['totals']);
        $this->assertCount(3, $accepted['slices']);

        foreach ($b->fresh()->lines as $line) {
            $snap = $line->accepted_snapshot;
            $slice = collect($accepted['slices'])->firstWhere('line_id', $line->id);
            $this->assertEquals($slice['payable'], $snap['payable']);
            $this->assertEquals($slice['original']['total'], $snap['original']['total']);
        }
        $this->assertSame(round((150 + 140 + 50) * 0.9, 2), $accepted['totals']['total_amount']);
    }

    /** @test */
    public function rounding_residuals_are_assigned_deterministically_and_header_reconciles(): void
    {
        $r = $this->priced($this->receipt($this->supplier, '30'), 100);
        $b = $this->doc(['global_discount_type' => 'fixed', 'global_discount_value' => 10]);
        $d1 = Location::create(['setting_id' => $this->setting->id, 'name' => 'D1', 'is_consignment' => false]);
        $d2 = Location::create(['setting_id' => $this->setting->id, 'name' => 'D2', 'is_consignment' => false]);
        $d3 = Location::create(['setting_id' => $this->setting->id, 'name' => 'D3', 'is_consignment' => false]);
        foreach ([$d1, $d2, $d3] as $d) {
            // One receipt, three destinations: distinct slices of the same product and receipt.
            ConsignmentBuyoutLine::create(['consignment_buyout_id' => $b->id, 'line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $r->id,
                'product_id' => $this->product->id, 'source_location_id' => $this->location->id, 'destination_location_id' => $d->id,
                'base_quantity' => '1', 'original_snapshot' => []]);
        }

        $a = $this->pricing->calculate($b->fresh());
        $this->assertTrue($a['valid'], implode(' ', $a['blockers']));
        $this->assertSame(10.0, round(array_sum(array_column($a['slices'], 'document_discount')), 2));
        $this->assertSame(290.0, round(array_sum(array_column($a['slices'], 'payable')), 2));
        $this->assertSame($a, $this->pricing->calculate($b->fresh()), 'deterministic');
    }

    /** @test */
    public function zero_cost_is_allowed_but_overlarge_discounts_and_invalid_inputs_are_rejected(): void
    {
        $r = $this->priced($this->receipt($this->supplier, '10'), 100);
        $b = $this->doc(['global_discount_type' => 'percentage', 'global_discount_value' => 100]);
        $this->line($b, $r, 'UNSOLD_BUYOUT', '2');
        $res = $this->pricing->calculate($b->fresh());
        $this->assertTrue($res['valid'], implode(' ', $res['blockers']));
        $this->assertSame(0.0, $res['totals']['total_amount']);
        $this->assertSame(0.0, $res['slices'][0]['inventory_cost']);

        foreach ([
            'discount exceeds total' => ['global_discount_type' => 'fixed', 'global_discount_value' => 500],
            'negative discount' => ['global_discount_type' => 'fixed', 'global_discount_value' => -1],
            'percentage over 100' => ['global_discount_type' => 'percentage', 'global_discount_value' => 150],
        ] as $label => $intent) {
            $bad = $this->doc($intent);
            $this->line($bad, $r, 'UNSOLD_BUYOUT', '2');
            $this->assertFalse($this->pricing->calculate($bad->fresh())['valid'], $label);
        }

        $rowBad = $this->doc();
        $line = $this->line($rowBad, $r, 'UNSOLD_BUYOUT', '2');
        $rowBad->update(['pricing_intent' => ['rows' => [$line->id => ['unit_price' => -5]]]]);
        $this->assertFalse($this->pricing->calculate($rowBad->fresh())['valid']);
        $rowBad->update(['pricing_intent' => ['rows' => [$line->id => ['discount_type' => 'percentage', 'discount_value' => 120]]]]);
        $this->assertFalse($this->pricing->calculate($rowBad->fresh())['valid']);

        $this->expectException(\InvalidArgumentException::class);
        $this->pricing->accept($rowBad->fresh());
    }

    /** @test */
    public function row_overrides_and_discounts_follow_the_shared_calculator(): void
    {
        $r = $this->priced($this->receipt($this->supplier, '10'), 100);
        $b = $this->doc();
        $l1 = $this->line($b, $r, 'UNSOLD_BUYOUT', '2');
        $b->update(['pricing_intent' => ['rows' => [$l1->id => ['discount_type' => 'percentage', 'discount_value' => 10]]]]);
        $res = $this->pricing->calculate($b->fresh());
        $this->assertSame(180.0, $res['slices'][0]['accepted']['total']);
        $this->assertSame(180.0, $res['slices'][0]['inventory_cost']);

        $b->update(['pricing_intent' => ['rows' => [$l1->id => ['row_total_override' => 150]]]]);
        $res = $this->pricing->calculate($b->fresh());
        $this->assertSame(150.0, $res['slices'][0]['accepted']['total']);
        $this->assertSame(200.0, $res['slices'][0]['original']['total'], 'original evidence stays separate');
    }

    // ---------------------------------------------------------------- 5.2 PKP / tax

    /** @test */
    public function non_pkp_forged_tax_is_neutralized_and_never_enters_totals(): void
    {
        $tax = Tax::create(['name' => 'PPN', 'value' => 11, 'is_default' => true, 'is_active' => true]);
        $r = $this->priced($this->receipt($this->supplier, '10'), 100, 0, 22.0, $tax->id, 11.0); // receipt carries tax evidence
        $b = $this->doc();
        $l = $this->line($b, $r, 'UNSOLD_BUYOUT', '2');
        $b->update(['pricing_intent' => ['is_tax_included' => true, 'rows' => [$l->id => ['tax_id' => $tax->id, 'unit_price' => 100]]]]);

        $this->assertFalse((bool) $this->setting->fresh()->is_pkp);
        $res = $this->pricing->calculate($b->fresh());
        $this->assertTrue($res['neutralized_tax_inputs']);
        $this->assertTrue($res['valid'], implode(' ', $res['blockers']));
        $this->assertSame(0.0, $res['slices'][0]['accepted']['tax_amount']);
        $this->assertNull($res['slices'][0]['accepted']['tax_id']);
        $this->assertSame(200.0, $res['totals']['total_amount']);
        $this->assertSame(200.0, $res['slices'][0]['inventory_cost']);
    }

    /** @test */
    public function pkp_unchanged_terms_reproduce_original_amounts_and_cost_excludes_recoverable_tax(): void
    {
        $this->setting->update(['is_pkp' => true]);
        $tax = Tax::create(['name' => 'PPN', 'value' => 11, 'is_default' => true, 'is_active' => true]);
        $r = $this->priced($this->receipt($this->supplier, '10'), 100, 0, 110.0, $tax->id, 11.0); // 10 units => tax 110
        $b = $this->doc(['is_tax_included' => false]);
        $l = $this->line($b, $r, 'UNSOLD_BUYOUT', '5'); // slice tax = 110 * 5/10 = 55

        $res = $this->pricing->calculate($b->fresh());
        $s = $res['slices'][0];
        $this->assertTrue($res['valid'], implode(' ', $res['blockers']));
        $this->assertSame(55.0, $s['original']['tax_amount']);
        $this->assertSame(555.0, $s['original']['total']);
        $this->assertSame($s['original']['total'], $s['accepted']['total']);
        $this->assertSame(55.0, $s['accepted']['tax_amount']);
        $this->assertSame(500.0, $s['inventory_cost'], 'recoverable tax is not inventory cost');

        // A document discount reduces cost only by its net-of-tax share: 55.5 off 555 => 50 off the 500 base.
        $b->update(['pricing_intent' => ['is_tax_included' => false, 'global_discount_type' => 'fixed', 'global_discount_value' => 55.5]]);
        $d = $this->pricing->calculate($b->fresh());
        $this->assertSame(499.5, $d['totals']['total_amount']);
        $this->assertSame(450.0, $d['slices'][0]['inventory_cost']);
    }

    /** @test */
    public function an_invalid_row_tax_selection_is_a_blocker_for_pkp(): void
    {
        $this->setting->update(['is_pkp' => true]);
        $r = $this->priced($this->receipt($this->supplier, '10'), 100);
        $b = $this->doc();
        $l = $this->line($b, $r, 'UNSOLD_BUYOUT', '2');
        $b->update(['pricing_intent' => ['rows' => [$l->id => ['tax_id' => 424242, 'unit_price' => 100]]]]);
        $res = $this->pricing->calculate($b->fresh());
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('not a valid active tax', implode(' ', $res['blockers']));
    }
}
