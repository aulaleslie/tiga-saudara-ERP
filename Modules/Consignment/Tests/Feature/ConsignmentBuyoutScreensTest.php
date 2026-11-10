<?php

namespace Modules\Consignment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Tests\Support\BuildsBuyoutFixtures;
use Modules\Consignment\Tests\Support\BuildsFinalizedBuyout;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Buyout routes and screens. Read screens render for permitted users; every mutation endpoint is a hidden 404 with no
 * side effect unless the UI flag is on, and then still needs the dedicated permissions.
 */
class ConsignmentBuyoutScreensTest extends TestCase
{
    use RefreshDatabase;
    use BuildsBuyoutFixtures;
    use BuildsFinalizedBuyout;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFinalizationFixtures();
        $this->withoutMiddleware(\App\Http\Middleware\CheckUserRoleForSetting::class);
        $this->viewer = $this->userWith(['consignments.buyouts.access']);
    }

    private function userWith(array $perms): User
    {
        $u = User::factory()->create();
        $u->settings()->attach($this->setting->id, ['role_id' => Role::findOrCreate('BR', 'web')->id]);
        foreach ($perms as $p) {
            Permission::findOrCreate($p, 'web');
            $u->givePermissionTo($p);
        }

        return $u;
    }

    private function as(User $u)
    {
        return $this->actingAs($u)->withSession(['setting_id' => $this->setting->id]);
    }

    /** @test */
    public function read_screens_need_the_access_permission_and_render_for_permitted_users(): void
    {
        [$b] = $this->mixed(null, 120.0);

        $this->as(User::factory()->create())->get(route('consignments.buyouts.index'))->assertForbidden();
        $this->as($this->viewer)->get(route('consignments.buyouts.index'))->assertOk()->assertSee($b->reference)->assertSee('belum diaktifkan');
        $this->as($this->viewer)->get(route('consignments.buyouts.index', ['status' => 'REVERSED']))->assertOk()->assertDontSee($b->reference);
        $this->as($this->viewer)->get(route('consignments.buyouts.show', $b->id))->assertOk()
            ->assertSee($b->reference)->assertSee('DISETUJUI')->assertSee('Terjual belum ditagih')->assertSee('Belum terjual (dibeli)')
            ->assertDontSee('Setujui &amp; Cadangkan', false);                       // no actions while disabled
        $this->as(User::factory()->create())->get(route('consignments.buyouts.show', $b->id))->assertForbidden();
    }

    /** @test */
    public function the_preparation_screen_shows_capacity_attribution_gaps_and_blockers_without_selecting_anything(): void
    {
        $r = $this->receipt($this->supplier, '10');
        $this->stock('10');
        $this->soldSource('4');     // an effective sale in the pool that nobody attributed yet

        $html = $this->as($this->viewer)->get(route('consignments.buyouts.create', ['supplier_id' => $this->supplier->id]))
            ->assertOk()->assertSee('Penjualan belum diatribusikan')->getContent();
        $this->assertStringContainsString('fully attributed', $html);
        $this->assertStringContainsString('Tersedia untuk dibeli', $html);
        $this->assertStringNotContainsString('name="lines[', $html, 'no draft form while the feature is disabled');
    }

    /** @test */
    public function every_mutation_endpoint_is_a_hidden_404_without_side_effects_while_disabled(): void
    {
        [$b] = $this->mixed(null, 120.0);
        $admin = $this->userWith(['consignments.buyouts.create', 'consignments.buyouts.approve', 'consignments.buyouts.cancel', 'consignments.buyouts.finalize',
            'consignments.buyouts.reverse', 'consignments.billing.convert', 'consignments.buyouts.acquire_unsold', 'consignments.allocations.approve', 'consignments.buyouts.access']);
        $before = [\DB::table('consignment_buyouts')->count(), \DB::table('consignment_buyout_reservations')->where('status', 'ACTIVE')->count(), \DB::table('purchases')->count()];

        foreach ([
            ['post', route('consignments.buyouts.store'), []],
            ['put', route('consignments.buyouts.update', $b->id), []],
            ['post', route('consignments.buyouts.submit', $b->id), []],
            ['post', route('consignments.buyouts.approve', $b->id), []],
            ['post', route('consignments.buyouts.reject', $b->id), ['reason' => 'x']],
            ['post', route('consignments.buyouts.cancel', $b->id), ['reason' => 'x', 'confirm' => 1]],
            ['post', route('consignments.buyouts.finalize', $b->id), ['confirm' => 1]],
            ['post', route('consignments.buyouts.reverse', $b->id), ['reason' => 'x', 'confirm' => 1]],
            ['post', route('consignments.buyouts.attribute', 1), ['quantities' => [1 => 1], 'reason' => 'x']],
            ['post', route('consignments.buyouts.returns.declare', 1), ['acquired_quantity' => 1]],
        ] as [$method, $url, $data]) {
            $this->as($admin)->{$method}($url, $data)->assertNotFound();
        }
        $this->assertSame($before, [\DB::table('consignment_buyouts')->count(), \DB::table('consignment_buyout_reservations')->where('status', 'ACTIVE')->count(), \DB::table('purchases')->count()]);
        $this->assertSame('APPROVED', $b->fresh()->status);
    }

    /** @test */
    public function when_enabled_forged_requests_get_403_and_business_errors_keep_ordinary_handling(): void
    {
        config(['consignment.buyouts.ui_enabled' => true, 'consignment.buyouts.finalization_enabled' => true]);
        [$b] = $this->mixed(null, 120.0);
        $snap = [\DB::table('consignment_buyout_reservations')->where('status', 'ACTIVE')->count(), \DB::table('purchases')->count()];

        // No dedicated permission: 403 (not a toast, not a redirect) and nothing changes.
        $viewer = $this->viewer;
        $this->as($viewer)->post(route('consignments.buyouts.cancel', $b->id), ['reason' => 'x', 'confirm' => 1])->assertForbidden();
        $this->as($viewer)->post(route('consignments.buyouts.finalize', $b->id), ['confirm' => 1])->assertForbidden();
        $this->as($viewer)->post(route('consignments.buyouts.reverse', $b->id), ['reason' => 'x', 'confirm' => 1])->assertForbidden();
        $this->assertSame('APPROVED', $b->fresh()->status);

        // Permission but explicit confirmation missing: validation error, nothing changes.
        $admin = $this->userWith(['consignments.buyouts.access', 'consignments.buyouts.cancel']);
        $this->as($admin)->post(route('consignments.buyouts.cancel', $b->id), ['reason' => 'x'])->assertSessionHasErrors('confirm');
        $this->assertSame('APPROVED', $b->fresh()->status);

        // Business-rule rejection (not submitted/approved yet): ordinary redirect with an error, no 500.
        $draft = $this->life->createDraft($this->setting->id, $this->supplier->id, '2026-11-10', [[
            'line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $b->lines()->first()->consignment_receiving_detail_id,
            'destination_location_id' => $this->dest1->id, 'base_quantity' => '1', 'buckets' => ['NON_TAXED' => '1']]], $this->user, $this->meta());
        $approver = $this->userWith(['consignments.buyouts.access', 'consignments.buyouts.approve', 'consignments.allocations.approve', 'consignments.buyouts.acquire_unsold']);
        $this->as($approver)->post(route('consignments.buyouts.approve', $draft->id))->assertRedirect();
        $this->assertSame('DRAFT', $draft->fresh()->status);

        // Lifecycle through the routes when everything is enabled and permitted.
        $ops = $this->userWith(['consignments.buyouts.access', 'consignments.buyouts.submit', 'consignments.buyouts.approve', 'consignments.buyouts.cancel',
            'consignments.allocations.approve', 'consignments.buyouts.acquire_unsold']);
        $this->as($ops)->post(route('consignments.buyouts.submit', $draft->id), ['revision' => $draft->revision])->assertRedirect(route('consignments.buyouts.show', $draft->id));
        $this->assertSame('WAITING_APPROVAL', $draft->fresh()->status);
        $this->as($ops)->post(route('consignments.buyouts.approve', $draft->id), ['revision' => 99])->assertRedirect();   // stale revision
        $this->assertSame('WAITING_APPROVAL', $draft->fresh()->status);
        $this->as($ops)->post(route('consignments.buyouts.approve', $draft->id), ['revision' => $draft->revision])->assertRedirect();
        $this->assertSame('APPROVED', $draft->fresh()->status);
        $this->as($ops)->post(route('consignments.buyouts.cancel', $draft->id), ['reason' => 'tidak jadi', 'confirm' => 1])->assertRedirect();
        $this->assertSame('CANCELLED', $draft->fresh()->status);
        $this->assertSame(0, $draft->fresh()->reservations()->where('status', 'ACTIVE')->count());
    }

    /** @test */
    public function show_screen_offers_only_status_appropriate_permitted_actions_when_enabled(): void
    {
        config(['consignment.buyouts.ui_enabled' => true]);
        [$b] = $this->mixed(null, 120.0);                                         // APPROVED
        $admin = $this->userWith(['consignments.buyouts.access', 'consignments.buyouts.cancel', 'consignments.buyouts.finalize', 'consignments.buyouts.reverse']);

        $html = $this->as($admin)->get(route('consignments.buyouts.show', $b->id))->assertOk()->getContent();
        $this->assertStringContainsString('Batalkan Dokumen', $html);
        $this->assertStringContainsString('Finalisasi', $html);
        $this->assertStringNotContainsString('Ajukan Persetujuan', $html);       // not a draft
        $this->assertStringNotContainsString('>Reversal<', $html);                // not final
        $this->assertStringContainsString('name="confirm"', $html);               // explicit confirmation controls

        $limited = $this->userWith(['consignments.buyouts.access']);
        $html = $this->as($limited)->get(route('consignments.buyouts.show', $b->id))->getContent();
        $this->assertStringNotContainsString('Batalkan Dokumen', $html);
        $this->assertStringNotContainsString('Finalisasi', $html);
    }

    /** @test */
    public function reconciliation_and_purchase_screens_separate_attribution_reservations_acquisitions_payments_and_reversal(): void
    {
        [$b] = $this->mixed(null, 120.0);
        $this->fin->finalize($b->fresh(), $this->user);
        $rec = $this->userWith(['consignments.allocations.access', 'consignments.buyouts.access', 'consignments.access']);

        $html = $this->as($rec)->get(route('consignments.reconciliation.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Pembelian Putus per Penerimaan', $html);
        $this->assertStringContainsString($b->reference, $html);
        $this->assertStringContainsString('Cadangan terpakai', $html);

        $purchase = $b->fresh()->purchase;
        $show = $this->userWith(['consignments.buyouts.access']);
        $show->assignRole(Role::findOrCreate('Super Admin', 'web'));
        $this->as($show)->get(route('purchases.show', $purchase->id))->assertOk()->assertSee('Dokumen Pembelian Putus Konsinyasi')->assertSee($b->reference);

        (new \Modules\Consignment\Services\ConsignmentBuyoutReversalService())->reverse($b->fresh(), $this->user, 'undo');
        $this->as($show)->get(route('purchases.show', $purchase->id))->assertOk()->assertSee('telah dibatalkan melalui reversal');
        $html = $this->as($rec)->get(route('consignments.reconciliation.index'))->getContent();
        $this->assertStringContainsString('dibatalkan', $html);
    }

    /** @test */
    public function drafts_are_created_from_exactly_the_submitted_rows_and_forged_identities_are_rejected_without_substitution(): void
    {
        config(['consignment.buyouts.ui_enabled' => true]);
        $r = $this->receipt($this->supplier, '10');
        $this->stock('10');
        $maker = $this->userWith(['consignments.buyouts.access', 'consignments.buyouts.create', 'consignments.buyouts.acquire_unsold']);
        $payload = fn (array $line) => ['supplier_id' => $this->supplier->id, 'date' => '2026-11-10',
            'invoice_metadata' => ['supplier_invoice_number' => 'INV-S', 'invoice_date' => '2026-11-10'],
            'lines' => ['u0' => $line, 'u1' => ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $r->id, 'base_quantity' => '']]];
        $good = ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $r->id, 'destination_location_id' => $this->dest1->id,
            'base_quantity' => '2.500', 'buckets' => ['TAXED' => '', 'NON_TAXED' => '2.500']];

        $this->as($maker)->post(route('consignments.buyouts.store'), $payload($good))->assertRedirect();
        $b = ConsignmentBuyout::latest('id')->first();
        $this->assertSame('DRAFT', $b->status);
        $this->assertCount(1, $b->lines, 'the blank row is not part of the document and nothing was invented for it');
        $this->assertEquals('2.500', $b->lines->first()->base_quantity);

        // A receipt of ANOTHER supplier is rejected whole - no document, no substituted receipt.
        $other = \Modules\People\Entities\Supplier::create(['setting_id' => $this->setting->id, 'supplier_name' => 'Other', 'supplier_email' => 'o@e.com',
            'supplier_phone' => '0899', 'city' => 'J', 'country' => 'ID', 'address' => 'A']);
        $foreign = $this->receipt($other, '5');
        $count = ConsignmentBuyout::count();
        $this->as($maker)->post(route('consignments.buyouts.store'), $payload(['consignment_receiving_detail_id' => $foreign->id] + $good))->assertRedirect();
        $this->assertSame($count, ConsignmentBuyout::count());

        // Invalid shapes fail validation before anything is written.
        $this->as($maker)->post(route('consignments.buyouts.store'), $payload(['base_quantity' => '-1'] + $good))->assertSessionHasErrors();
        $this->as($maker)->post(route('consignments.buyouts.store'), $payload(['line_type' => 'OTHER'] + $good))->assertSessionHasErrors();
        $this->assertSame($count, ConsignmentBuyout::count());

        // A user without the unsold-acquisition authority cannot create an unsold draft at all.
        $sold = $this->userWith(['consignments.buyouts.access', 'consignments.buyouts.create']);
        $this->as($sold)->post(route('consignments.buyouts.store'), $payload($good))->assertForbidden();
        $this->assertSame($count, ConsignmentBuyout::count());
    }
}
