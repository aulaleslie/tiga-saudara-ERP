<?php

namespace Modules\Consignment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Consignment\Services\ConsignmentBuyoutProposalService;
use Modules\Consignment\Tests\Support\BuildsBuyoutFixtures;
use Modules\Consignment\Tests\Support\BuildsFinalizedBuyout;
use Modules\Product\Entities\ProductSerialNumber;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The agreed "buy all eligible" capability: a read-only proposal for an explicitly chosen destination, with every
 * supplier receipt/sold source shown once as included or excluded-with-reasons, no silent substitution, and no write.
 */
class ConsignmentBuyoutAllEligibleTest extends TestCase
{
    use RefreshDatabase;
    use BuildsBuyoutFixtures;
    use BuildsFinalizedBuyout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinalizationFixtures();
        $this->withoutMiddleware(\App\Http\Middleware\CheckUserRoleForSetting::class);
    }

    private function viewer(): User
    {
        $u = User::factory()->create();
        $u->settings()->attach($this->setting->id, ['role_id' => Role::findOrCreate('BR', 'web')->id]);
        Permission::findOrCreate('consignments.buyouts.access', 'web');
        $u->givePermissionTo('consignments.buyouts.access');

        return $u;
    }

    private function propose(): array
    {
        return (new ConsignmentBuyoutProposalService())->allEligible($this->setting->id, $this->supplier->id, $this->dest1->id);
    }

    private function tables(): array
    {
        return array_map(fn ($t) => \DB::table($t)->count(), ['consignment_buyouts', 'consignment_buyout_lines', 'consignment_buyout_reservations',
            'consignment_active_serial_claims', 'purchases', 'consignment_buyout_audit_events']);
    }

    /** @test */
    public function proposes_unsold_and_sold_rows_separately_clipped_by_physical_stock_and_writes_nothing(): void
    {
        $r = $this->receipt($this->supplier, '10');
        $this->price($r, 100);
        $src = $this->soldSource('4');
        $this->capacity()->setAttribution($src->id, [$r->id => '4'], null, 'attribute');
        $this->stock('6');                                    // receipt says 6 unsold, physical 6
        $before = $this->tables();

        $out = $this->propose();

        $this->assertSame($before, $this->tables(), 'a proposal creates nothing');
        $this->assertCount(1, $out['unsold']);
        $this->assertCount(1, $out['sold']);
        $unsold = $out['unsold'][0];
        $this->assertTrue($unsold['included']);
        $this->assertEquals(6.0, $unsold['proposed']);
        $this->assertEquals(6.0, $unsold['buckets']['NON_TAXED'] + $unsold['buckets']['TAXED']);
        $this->assertSame('SOLD_UNBILLED', $out['sold'][0]['line_type']);
        $this->assertEquals(4.0, $out['sold'][0]['proposed']);
        $this->assertEquals(['unsold' => 6.0, 'sold' => 4.0], $out['totals']);
    }

    /** @test */
    public function two_receipts_sharing_a_pool_never_both_get_the_same_physical_goods_and_the_clip_is_visible(): void
    {
        $a = $this->receipt($this->supplier, '5');
        $b = $this->receipt($this->supplier, '5');
        $this->stock('7');                                    // receipts say 10 residual, only 7 physically present
        $out = $this->propose();

        $included = array_sum(array_map(fn ($r) => $r['proposed'], $out['unsold']));
        $this->assertLessThanOrEqual(7.0, $included, 'never more than the physical pool');
        // Two receipts with a 7-unit physical pool trip the ambiguous-residual blocker: nothing is guessed, both are excluded with the reason.
        foreach ($out['unsold'] as $row) {
            $this->assertFalse($row['included'], 'ambiguous pool: nothing proposed');
            $this->assertNotEmpty($row['reasons']);
        }
        $this->assertStringContainsString('Sellable stock', implode(' ', $out['unsold'][0]['reasons']));
    }

    /** @test */
    public function two_receipts_in_one_sufficient_pool_split_it_exactly_without_double_counting(): void
    {
        $this->receipt($this->supplier, '5');
        $this->receipt($this->supplier, '5');
        $this->stock('10');
        $out = $this->propose();

        $this->assertEquals([5.0, 5.0], array_map(fn ($r) => $r['proposed'], $out['unsold']));
        $this->assertEquals(10.0, $out['totals']['unsold']);
        $this->assertEquals(10.0, $out['unsold'][0]['pool_remaining_before']['non_taxed'], 'first receipt saw the whole pool');
        $this->assertEquals(5.0, $out['unsold'][1]['pool_remaining_before']['non_taxed'], 'second saw only what the first left');
    }

    /** @test */
    public function an_unattributed_sale_excludes_the_pool_with_a_reason_instead_of_being_guessed(): void
    {
        $r = $this->receipt($this->supplier, '10');
        $this->stock('10');
        $src = $this->soldSource('3');                        // never attributed
        $out = $this->propose();

        $this->assertFalse($out['unsold'][0]['included']);
        $this->assertStringContainsString('belum diatribusikan', implode(' ', $out['unsold'][0]['reasons']));
        $this->assertSame($src->id, $out['unattributed'][0]['source_id']);
        $this->assertSame(0.0, $out['totals']['unsold']);
    }

    /** @test */
    public function serialized_receipts_list_exact_serials_and_never_exceed_what_is_selectable(): void
    {
        \DB::table('products')->where('id', $this->product->id)->update(['serial_number_required' => true]);
        $r = $this->receipt($this->supplier, '3');
        $this->stock('3');
        foreach (['S1', 'S2'] as $sn) {                       // only two of the three units are selectable serials
            ProductSerialNumber::create(['product_id' => $this->product->id, 'location_id' => $this->location->id, 'serial_number' => $sn,
                'consignment_receiving_detail_id' => $r->id, 'is_broken' => false, 'status' => 'active']);
        }
        $row = $this->propose()['unsold'][0];

        $this->assertEquals(2.0, $row['proposed']);
        $this->assertCount(2, $row['serial_ids']);
        $this->assertStringContainsString('Dibatasi', implode(' ', $row['reasons']), 'requested 3, proposed 2 is shown, not hidden');
        $this->assertTrue($row['included']);
    }

    /** @test */
    public function another_suppliers_receipts_are_never_included(): void
    {
        $other = \Modules\People\Entities\Supplier::create(['setting_id' => $this->setting->id, 'supplier_name' => 'Other', 'supplier_email' => 'o@e.com',
            'supplier_phone' => '0822', 'city' => 'J', 'country' => 'ID', 'address' => 'A']);
        $this->receipt($other, '4');
        $this->stock('4');

        $out = $this->propose();
        $this->assertSame([], $out['unsold']);
        $this->assertSame([], $out['sold']);
    }

    /** @test */
    public function the_screen_requires_an_explicit_destination_and_shows_a_review_with_exclusions_while_mutations_are_disabled(): void
    {
        $r = $this->receipt($this->supplier, '10');
        $this->stock('10');
        $this->soldSource('3');                               // unattributed -> excluded
        $user = $this->viewer();
        $before = $this->tables();

        $none = $this->actingAs($user)->withSession(['setting_id' => $this->setting->id])
            ->get(route('consignments.buyouts.create', ['supplier_id' => $this->supplier->id, 'propose' => 'all']))
            ->assertOk()->assertSee('Pilih lokasi tujuan')->assertDontSee('Tinjauan: semua yang memenuhi syarat');
        $this->assertNotNull($none);

        $html = $this->actingAs($user)->withSession(['setting_id' => $this->setting->id])
            ->get(route('consignments.buyouts.create', ['supplier_id' => $this->supplier->id, 'propose' => 'all', 'destination_location_id' => $this->dest1->id]))
            ->assertOk()->assertSee('Tinjauan: semua yang memenuhi syarat')->assertSee('Dikecualikan')->assertSee('Usulkan semua yang memenuhi syarat')
            ->getContent();
        $this->assertStringContainsString('belum diatribusikan', $html);
        $this->assertStringNotContainsString('name="lines[', $html, 'no draft form while the feature is disabled');
        $this->assertSame($before, $this->tables());
    }

    /** @test */
    public function with_the_ui_enabled_the_review_prefills_the_draft_and_an_unticked_row_is_an_explicit_exclusion(): void
    {
        config(['consignment.buyouts.ui_enabled' => true]);
        $a = $this->receipt($this->supplier, '4');
        $this->stock('4');
        $user = $this->viewer();
        foreach (['consignments.buyouts.create'] as $p) {
            Permission::findOrCreate($p, 'web');
            $user->givePermissionTo($p);
        }

        $html = $this->actingAs($user)->withSession(['setting_id' => $this->setting->id])
            ->get(route('consignments.buyouts.create', ['supplier_id' => $this->supplier->id, 'propose' => 'all', 'destination_location_id' => $this->dest1->id]))
            ->assertOk()->getContent();
        $this->assertStringContainsString('name="lines[u0][include]"', $html);
        $this->assertMatchesRegularExpression('/name="lines\[u0\]\[base_quantity\]"[^>]*value="4"/', $html, 'quantity prefilled from the review');

        // Unticked => reviewed but not included => dropped; nothing remains, so validation (not a silent default) answers.
        $this->actingAs($user)->withSession(['setting_id' => $this->setting->id])->post(route('consignments.buyouts.store'), [
            'supplier_id' => $this->supplier->id, 'date' => '2026-11-10',
            'lines' => ['u0' => ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $a->id, 'reviewed' => 1,
                'base_quantity' => '4', 'destination_location_id' => $this->dest1->id, 'buckets' => ['NON_TAXED' => '4']]],
        ])->assertSessionHasErrors('lines');
        $this->assertSame(0, \DB::table('consignment_buyouts')->count());
    }
}
