<?php

namespace Modules\Consignment\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Consignment\Entities\ConsignmentBuyout;
use Modules\Consignment\Entities\ConsignmentBuyoutCostEvent;
use Modules\Consignment\Entities\ConsignmentBuyoutLine;
use Modules\Consignment\Services\ConsignmentBuyoutFinalizationService;
use Modules\Consignment\Services\ConsignmentBuyoutLifecycleService;
use Modules\Consignment\Tests\Support\BuildsRemoteProvenanceFixtures;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductSerialNumber;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 5: the original receipt business (A) buys out unsold supplier stock wherever it physically is. Remote units
 * become owned by the holding business (B) through B's own standard destination; one A Purchase owes the supplier.
 * HYPE 2-like shape: 3 received, 1 unsold at A, 2 unsold transferred to B.
 */
class ConsignmentRemoteBuyoutTest extends TestCase
{
    use RefreshDatabase;
    use BuildsRemoteProvenanceFixtures;

    private const PERMS = ['consignments.buyouts.create', 'consignments.buyouts.edit', 'consignments.buyouts.submit', 'consignments.buyouts.approve',
        'consignments.buyouts.cancel', 'consignments.buyouts.acquire_unsold', 'consignments.allocations.approve', 'consignments.buyouts.finalize',
        'consignments.buyouts.reverse', 'consignments.billing.convert', 'consignments.buyouts.acquire_remote'];

    private ConsignmentBuyoutLifecycleService $life;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRemoteFixtures();
        config(['consignment.buyouts.finalization_enabled' => true]);
        $this->life = app(ConsignmentBuyoutLifecycleService::class);
        $this->moveSerial($this->sn[1], $this->ca, $this->cb);
        $this->moveSerial($this->sn[2], $this->ca, $this->cb);
        foreach ([$this->businessA, $this->businessB] as $s) {
            ProductPrice::create(['product_id' => $this->serialized->id, 'setting_id' => $s->id, 'sale_price' => 1, 'last_purchase_price' => 555, 'average_purchase_price' => 100]);
        }
        foreach (self::PERMS as $p) {
            Permission::findOrCreate($p, 'web');
        }
    }

    private function grantActor(array $perms, array $settings): void
    {
        $this->actor->givePermissionTo($perms);
        foreach ($settings as $s) {
            $this->actor->settings()->syncWithoutDetaching([$s->id => ['role_id' => Role::findOrCreate('BO', 'web')->id]]);
        }
    }

    private function draft(?int $remoteDest = null): ConsignmentBuyout
    {
        return $this->life->createDraft($this->businessA->id, $this->supplier->id, now()->toDateString(), [
            ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $this->receipt->id, 'destination_location_id' => $this->a1->id,
                'base_quantity' => '1', 'serial_ids' => [$this->sn[0]->id]],
            ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $this->receipt->id, 'source_location_id' => $this->cb->id,
                'destination_location_id' => $remoteDest ?? $this->b1->id, 'base_quantity' => '2', 'serial_ids' => [$this->sn[1]->id, $this->sn[2]->id]],
        ], $this->actor, ['invoice_metadata' => ['invoice_date' => now()->toDateString(), 'supplier_invoice_number' => 'CANDRA-BO-1']]);
    }

    /** @test */
    public function origin_buys_local_and_remote_unsold_stock_with_one_purchase_and_owner_scoped_cost(): void
    {
        $this->grantActor(self::PERMS, [$this->businessA, $this->businessB]);
        $b = $this->draft();
        $this->assertSame((int) $this->businessB->id, (int) $b->lines()->where('source_location_id', $this->cb->id)->value('inventory_owner_setting_id'));
        $this->assertNull($b->lines()->where('source_location_id', $this->ca->id)->value('inventory_owner_setting_id'), 'local lines keep the legacy shape');

        $b = $this->life->approve($this->life->submit($b, $this->actor), $this->actor);
        $transfersBefore = \DB::table('transfers')->count();
        $notesBefore = \Illuminate\Support\Facades\Schema::hasTable('received_notes') ? \DB::table('received_notes')->count() : 0;

        $purchase = app(ConsignmentBuyoutFinalizationService::class)->finalize($b->fresh(), $this->actor);

        $this->assertSame((int) $this->businessA->id, (int) $purchase->setting_id, 'one Purchase owed by the original business');
        $this->assertSame(1, \DB::table('purchases')->count());
        $this->assertSame((int) $this->b1->id, (int) ProductSerialNumber::find($this->sn[1]->id)->location_id, 'remote units become B-owned stock in B');
        $this->assertSame((int) $this->a1->id, (int) ProductSerialNumber::find($this->sn[0]->id)->location_id);
        foreach ($this->sn as $sn) {
            $this->assertSame((int) $this->receipt->id, (int) $sn->fresh()->consignment_receiving_detail_id, 'receipt lineage preserved');
        }
        $this->assertSame($transfersBefore, \DB::table('transfers')->count(), 'no physical transfer back to the origin');
        if (\Illuminate\Support\Facades\Schema::hasTable('received_notes')) {
            $this->assertSame($notesBefore, \DB::table('received_notes')->count(), 'no Received Note');
        }

        $txn = \DB::table('transactions')->where('type', 'CONSIGNMENT_BUYOUT')->get();
        $this->assertEqualsCanonicalizing([$this->businessA->id, $this->businessA->id, $this->businessB->id, $this->businessB->id],
            $txn->pluck('setting_id')->map(fn ($i) => (int) $i)->all(), 'each leg stays inside its holding business');

        $events = ConsignmentBuyoutCostEvent::where('consignment_buyout_id', $b->id)->orderBy('setting_id')->get();
        $this->assertSame([(int) $this->businessA->id, (int) $this->businessB->id], $events->pluck('setting_id')->map(fn ($i) => (int) $i)->all());
        $this->assertEquals(1.0, (float) $events[0]->acquired_quantity);
        $this->assertEquals(2.0, (float) $events[1]->acquired_quantity);
        foreach ([$this->businessA, $this->businessB] as $s) {
            $this->assertEquals(555, (float) ProductPrice::where('setting_id', $s->id)->value('last_purchase_price'), 'last purchase price untouched');
        }

        // Custody projection: everything accounted, nothing left as supplier-owned.
        $p = app(\Modules\Consignment\Services\ConsignmentTransferProvenanceService::class)->project($this->receipt->fresh());
        $this->assertTrue($p['conserved']);
        $this->assertEqualsWithDelta(3.0, $p['acquired'], 0.001);
        $this->assertSame([], $p['custody']);
    }

    /** @test */
    public function origin_only_biller_cannot_acquire_remote_stock(): void
    {
        $this->grantActor(array_diff(self::PERMS, ['consignments.buyouts.acquire_remote']), [$this->businessA, $this->businessB]);
        $this->expectException(AuthorizationException::class);
        $this->draft();
    }

    /** @test */
    public function delegation_without_membership_of_the_holding_business_is_refused(): void
    {
        $this->grantActor(self::PERMS, [$this->businessA]);
        $this->expectException(AuthorizationException::class);
        $this->draft();
    }

    /** @test */
    public function remote_units_must_land_in_a_standard_location_of_the_holding_business(): void
    {
        $this->grantActor(self::PERMS, [$this->businessA, $this->businessB]);
        $b = $this->draft($this->a2->id);   // origin standard location: wrong owner
        $this->assertStringContainsString('business holding the goods', implode(' ', $this->life->validateDocument($b)));
    }

    /** @test */
    public function local_selection_is_not_blocked_by_proven_remote_custody(): void
    {
        $this->grantActor(self::PERMS, [$this->businessA, $this->businessB]);
        $b = $this->life->createDraft($this->businessA->id, $this->supplier->id, now()->toDateString(), [
            ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $this->receipt->id, 'destination_location_id' => $this->a1->id,
                'base_quantity' => '1', 'serial_ids' => [$this->sn[0]->id]],
        ], $this->actor, ['invoice_metadata' => ['invoice_date' => now()->toDateString(), 'supplier_invoice_number' => 'X']]);
        $this->assertSame([], $this->life->validateDocument($b), 'one local unit is selectable although two moved to B');

        // Two remote units cannot be bought out "at" the origin location.
        $bad = $this->life->createDraft($this->businessA->id, $this->supplier->id, now()->toDateString(), [
            ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $this->receipt->id, 'destination_location_id' => $this->a1->id,
                'base_quantity' => '1', 'serial_ids' => [$this->sn[1]->id]],
        ], $this->actor, ['invoice_metadata' => ['invoice_date' => now()->toDateString(), 'supplier_invoice_number' => 'Y']]);
        $this->assertNotSame([], $this->life->validateDocument($bad));
    }

    /** @test */
    public function a_failure_after_the_last_remote_slice_rolls_back_everything(): void
    {
        $this->grantActor(self::PERMS, [$this->businessA, $this->businessB]);
        $b = $this->life->approve($this->life->submit($this->draft(), $this->actor), $this->actor);
        $before = [\DB::table('product_stocks')->get()->toArray(), \DB::table('product_serial_numbers')->get()->toArray(), \DB::table('product_prices')->get()->toArray()];

        $fin = new class extends ConsignmentBuyoutFinalizationService {
            protected function stage(string $name): void
            {
                if ($name === 'cost') {
                    throw new \RuntimeException('injected');
                }
            }
        };
        try {
            $fin->finalize($b->fresh(), $this->actor);
            $this->fail('injected failure swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('injected', $e->getMessage());
        }
        $this->assertSame(0, \DB::table('purchases')->count());
        $this->assertSame(0, ConsignmentBuyoutCostEvent::count());
        $this->assertEquals($before, [\DB::table('product_stocks')->get()->toArray(), \DB::table('product_serial_numbers')->get()->toArray(), \DB::table('product_prices')->get()->toArray()]);
        $this->assertSame(ConsignmentBuyout::STATUS_APPROVED, $b->fresh()->status);

        // Retry succeeds exactly once.
        $p1 = app(ConsignmentBuyoutFinalizationService::class)->finalize($b->fresh(), $this->actor);
        $p2 = app(ConsignmentBuyoutFinalizationService::class)->finalize($b->fresh(), $this->actor);
        $this->assertSame($p1->id, $p2->id);
        $this->assertSame(2, ConsignmentBuyoutCostEvent::count());
    }

    /** @test */
    public function proposal_all_selects_one_local_and_two_remote_units_once(): void
    {
        $svc = app(\Modules\Consignment\Services\ConsignmentBuyoutProposalService::class);
        $noDest = $svc->allEligible($this->businessA->id, $this->supplier->id, $this->a1->id);
        $remoteRow = collect($noDest['unsold'])->firstWhere('remote_custody', true);
        $this->assertFalse($remoteRow['included'], 'remote custody needs an explicit holding-business destination');
        $this->assertEqualsWithDelta(1.0, $noDest['totals']['unsold'], 0.001);

        $p = $svc->allEligible($this->businessA->id, $this->supplier->id, $this->a1->id, [$this->businessB->id => $this->b1->id]);
        $rows = collect($p['unsold'])->where('included', true);
        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(3.0, $p['totals']['unsold'], 0.001, 'receipt 3 = 1 local + 2 remote, consumed once');
        $remote = $rows->firstWhere('remote_custody', true);
        $this->assertSame($this->cb->id, $remote['source_location_id']);
        $this->assertSame($this->b1->id, $remote['destination_location_id']);
        $this->assertSame((int) $this->businessB->id, $remote['inventory_owner_setting_id']);
        $this->assertEqualsCanonicalizing([$this->sn[1]->id, $this->sn[2]->id], $remote['serial_ids']);

        // The proposal is directly usable as draft lines.
        $this->grantActor(self::PERMS, [$this->businessA, $this->businessB]);
        $lines = $rows->map(fn ($r) => ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $r['consignment_receiving_detail_id'],
            'source_location_id' => $r['source_location_id'], 'destination_location_id' => $r['destination_location_id'],
            'base_quantity' => (string) $r['proposed'], 'serial_ids' => $r['serial_ids']])->values()->all();
        $b = $this->life->createDraft($this->businessA->id, $this->supplier->id, now()->toDateString(), $lines, $this->actor,
            ['invoice_metadata' => ['invoice_date' => now()->toDateString(), 'supplier_invoice_number' => 'P']]);
        $this->assertSame([], $this->life->validateDocument($b));
        $this->assertSame(0, \DB::table('consignment_buyout_reservations')->count(), 'proposal and draft reserve nothing');
    }

    /** @test */
    public function remote_only_acquisition_leaves_the_origin_average_and_stock_untouched(): void
    {
        $this->grantActor(self::PERMS, [$this->businessA, $this->businessB]);
        $aStock = \DB::table('product_stocks')->whereIn('location_id', [$this->ca->id, $this->a1->id, $this->a2->id])->orderBy('id')->get()->toArray();
        $b = $this->life->createDraft($this->businessA->id, $this->supplier->id, now()->toDateString(), [
            ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $this->receipt->id, 'source_location_id' => $this->cb->id,
                'destination_location_id' => $this->b1->id, 'base_quantity' => '2', 'serial_ids' => [$this->sn[1]->id, $this->sn[2]->id]],
        ], $this->actor, ['invoice_metadata' => ['invoice_date' => now()->toDateString(), 'supplier_invoice_number' => 'R'],
            'pricing_intent' => ['rows' => []]]);
        $b = $this->life->approve($this->life->submit($b, $this->actor), $this->actor);
        $purchase = app(ConsignmentBuyoutFinalizationService::class)->finalize($b->fresh(), $this->actor);

        $this->assertSame((int) $this->businessA->id, (int) $purchase->setting_id);
        $this->assertEquals(100.0, (float) ProductPrice::where('setting_id', $this->businessA->id)->value('average_purchase_price'), 'no origin cost adjustment');
        $this->assertEquals($aStock, \DB::table('product_stocks')->whereIn('location_id', [$this->ca->id, $this->a1->id, $this->a2->id])->orderBy('id')->get()->toArray());
        $ev = ConsignmentBuyoutCostEvent::sole();
        $this->assertSame((int) $this->businessB->id, (int) $ev->setting_id);
        // B: Q = 2 (CB) + 1 (B1 own serial) = 3, A = 100, q = 2, C = 200 -> (300 - 200 + 200) / 3 = 100.
        $this->assertEquals(3.0, (float) $ev->total_quantity);
        $this->assertEquals((float) $ev->average_after, (float) ProductPrice::where('setting_id', $this->businessB->id)->value('average_purchase_price'));
    }

    /** @test */
    public function approval_reserves_remote_custody_and_finalization_rechecks_delegation_and_claims(): void
    {
        $this->grantActor(self::PERMS, [$this->businessA, $this->businessB]);
        $b = $this->life->approve($this->life->submit($this->draft(), $this->actor), $this->actor);

        // Coordination covers the origin and the holding business for the product.
        $pairs = $this->life->pairsOf($b->fresh());
        $this->assertContains([(int) $this->businessB->id, (int) $this->serialized->id], $pairs);

        // A competing buyout cannot claim the same remote serials.
        $other = $this->life->createDraft($this->businessA->id, $this->supplier->id, now()->toDateString(), [
            ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $this->receipt->id, 'source_location_id' => $this->cb->id,
                'destination_location_id' => $this->b1->id, 'base_quantity' => '1', 'serial_ids' => [$this->sn[1]->id]],
        ], $this->actor, ['invoice_metadata' => ['invoice_date' => now()->toDateString(), 'supplier_invoice_number' => 'Z']]);
        $this->assertNotSame([], $this->life->validateDocument($other));

        // A stock transfer cannot move a remote serial claimed by the approved buyout.
        session(['setting_id' => $this->businessB->id]);
        try {
            $this->moveSerial($this->sn[1], $this->cb, $this->ca);
            $this->fail('claimed remote serial transferred');
        } catch (\Throwable $e) {
        }

        // Delegation revoked after approval: finalization refuses and posts nothing.
        $this->actor->revokePermissionTo('consignments.buyouts.acquire_remote');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        try {
            app(ConsignmentBuyoutFinalizationService::class)->finalize($b->fresh(), $this->actor->fresh());
            $this->fail('finalized without delegation');
        } catch (AuthorizationException $e) {
        }
        $this->assertSame(0, \DB::table('purchases')->count());
    }

    /** @test */
    public function a_changed_remote_destination_after_approval_invalidates_the_approved_evidence(): void
    {
        $this->grantActor(self::PERMS, [$this->businessA, $this->businessB]);
        $b = $this->life->approve($this->life->submit($this->draft(), $this->actor), $this->actor);
        $other = \Modules\Setting\Entities\Location::create(['setting_id' => $this->businessB->id, 'name' => 'Gudang B2', 'is_active' => true]);
        \DB::table('consignment_buyout_lines')->where('source_location_id', $this->cb->id)->update(['destination_location_id' => $other->id]);

        $this->expectException(\InvalidArgumentException::class);
        app(ConsignmentBuyoutFinalizationService::class)->finalize($b->fresh(), $this->actor);
    }

    /** @test */
    public function historical_replay_applies_each_owner_event_only_in_its_business_and_infers_no_receipt(): void
    {
        $this->grantActor(self::PERMS, [$this->businessA, $this->businessB]);
        $b = $this->life->approve($this->life->submit($this->draft(), $this->actor), $this->actor);
        app(ConsignmentBuyoutFinalizationService::class)->finalize($b->fresh(), $this->actor);
        $engine = new \Modules\Purchase\Services\HistoricalReplayEngine();

        foreach ([$this->businessA, $this->businessB] as $s) {
            $events = $engine->collectTimelineEvents($this->serialized->id, now()->addMinute(), $s->id);
            $cost = $events->where('type', 'cost_replace');
            $this->assertCount(1, $cost, "one carrying-value event in business {$s->id}");
            $this->assertSame((int) $s->id, $cost->first()['setting_id']);
            $this->assertSame(0, $cost->first()['quantity']);
            $this->assertCount(0, $events->where('type', 'purchase'), 'the origin buyout Purchase is never a receipt');
        }
        // Owner-aware movement averages: each leg resolves its own business's event.
        $support = new class { use \App\Services\Reports\Concerns\InventoryReplaySupport; public function effects($t) { return $this->loadBuyoutTransactionEffects($t); } };
        $txns = \DB::table('transactions')->where('type', 'CONSIGNMENT_BUYOUT')->get();
        $effects = $support->effects($txns);
        $events = ConsignmentBuyoutCostEvent::get()->keyBy('setting_id');
        foreach ($txns as $t) {
            $e = $effects[$t->id];
            if (! $e['ignore']) {
                $this->assertEquals((float) $events[(int) $t->setting_id]->average_after, $e['average']);
            }
        }
    }

    /** @test */
    public function the_preparation_save_applies_one_destination_per_holding_business_to_local_and_remote_lines(): void
    {
        $this->withoutMiddleware([\App\Http\Middleware\CheckUserRoleForSetting::class]);
        config(['consignment.buyouts.ui_enabled' => true]);
        foreach (collect(require base_path('app/Config/Permissions.php'))->flatMap(fn ($g) => array_keys($g)) as $perm) {
            Permission::findOrCreate($perm, 'web');
        }
        $this->grantActor(array_merge(self::PERMS, ['consignments.buyouts.access']), [$this->businessA, $this->businessB]);

        $this->actingAs($this->actor)->withSession(['setting_id' => $this->businessA->id])->post(route('consignments.buyouts.store'), [
            'supplier_id' => $this->supplier->id, 'date' => now()->toDateString(),
            'destinations' => [$this->businessA->id => $this->a1->id, $this->businessB->id => $this->b1->id],
            'lines' => [
                'u0' => ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $this->receipt->id, 'source_location_id' => $this->ca->id,
                    'serial_ids' => [$this->sn[0]->id]],
                'u1' => ['line_type' => 'UNSOLD_BUYOUT', 'consignment_receiving_detail_id' => $this->receipt->id, 'source_location_id' => $this->cb->id,
                    'serial_ids' => [$this->sn[1]->id, $this->sn[2]->id]],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $b = ConsignmentBuyout::with('lines')->latest('id')->firstOrFail();
        $this->assertSame('DRAFT', $b->status);
        $remote = $b->lines->firstWhere('source_location_id', $this->cb->id);
        $this->assertSame((int) $this->b1->id, (int) $remote->destination_location_id, 'remote goods go to the holding business destination');
        $this->assertSame((int) $this->businessB->id, (int) $remote->inventory_owner_setting_id);
        $this->assertSame((int) $this->a1->id, (int) $b->lines->firstWhere('source_location_id', $this->ca->id)->destination_location_id);
        $this->assertEquals('2.000', $remote->base_quantity, 'quantity = chosen serials');
        $this->assertSame([], $this->life->validateDocument($b));
    }

    /** @test */
    public function review_screens_show_what_each_business_will_own_and_who_owes_the_supplier(): void
    {
        $this->withoutMiddleware([\App\Http\Middleware\CheckUserRoleForSetting::class]);
        config(['consignment.buyouts.ui_enabled' => true]);
        foreach (collect(require base_path('app/Config/Permissions.php'))->flatMap(fn ($g) => array_keys($g)) as $perm) {
            Permission::findOrCreate($perm, 'web');
        }
        $this->grantActor(array_merge(self::PERMS, ['consignments.buyouts.access']), [$this->businessA, $this->businessB]);

        $html = $this->actingAs($this->actor)->withSession(['setting_id' => $this->businessA->id])->get(route('consignments.buyouts.create', [
            'supplier_id' => $this->supplier->id, 'destination_location_id' => $this->a1->id,
            'remote_destinations' => [$this->businessB->id => $this->b1->id],
        ]))->assertOk()->getContent();
        $this->assertStringContainsString('menjadi milik <strong>' . e($this->businessB->company_name) . '</strong>', $html, 'acquiring business per current location');
        $this->assertStringContainsString('Lokasi saat ini: <strong>' . e($this->cb->name) . '</strong>', $html);
        foreach ($this->sn as $serial) {
            $this->assertLessThanOrEqual(1, substr_count($html, 'data-serial="' . $serial->serial_number . '"'), "serial {$serial->serial_number} is never offered twice");
        }
        $this->assertStringNotContainsString('sudah tampil di kelompok lain', $html, 'no phantom duplicate custody row');
        $this->assertStringContainsString('Tagihan supplier diterbitkan untuk: <strong>' . e($this->businessA->company_name) . '</strong>', $html);
        $this->assertStringContainsString('name="destinations[' . $this->businessB->id . ']"', $html, 'one destination per holding business');
        $this->assertMatchesRegularExpression('/name="lines\[u\d+\]\[source_location_id\]" value="' . $this->cb->id . '"/', $html, 'remote custody identity kept');
        $this->assertStringContainsString('"' . $this->businessB->id . '":' . $this->b1->id, $html, 'remote destination preselected from the request');

        $b = $this->draft();
        $show = $this->actingAs($this->actor)->withSession(['setting_id' => $this->businessA->id])
            ->get(route('consignments.buyouts.show', $b->id))->assertOk()->getContent();
        $this->assertStringContainsString('bisnis lain', $show);
        $this->assertStringContainsString('ditagih ke ' . e($this->businessA->company_name) . ' saja', $show);
    }

    /** @test */
    public function full_reversal_of_a_remote_acquisition_restores_every_owner_once(): void
    {
        $this->grantActor(self::PERMS, [$this->businessA, $this->businessB]);
        $snap = fn () => [\DB::table('product_stocks')->orderBy('id')->get(['location_id', 'quantity', 'quantity_tax', 'quantity_non_tax'])->toArray(),
            \DB::table('product_serial_numbers')->orderBy('id')->pluck('location_id', 'id')->all(),
            \DB::table('product_prices')->orderBy('id')->pluck('average_purchase_price', 'setting_id')->all()];
        $before = $snap();
        $b = $this->life->approve($this->life->submit($this->draft(), $this->actor), $this->actor);
        app(ConsignmentBuyoutFinalizationService::class)->finalize($b->fresh(), $this->actor);

        $rev = app(\Modules\Consignment\Services\ConsignmentBuyoutReversalService::class);
        $this->assertTrue($rev->preview($b->fresh())['eligible'], json_encode($rev->preview($b->fresh())['blockers']));
        $rev->reverse($b->fresh(), $this->actor, 'Salah input');
        $rev->reverse($b->fresh(), $this->actor, 'Salah input');   // repeat is a no-op

        $this->assertEquals($before, $snap(), 'stock, serial locations and both averages restored');
        $this->assertEqualsCanonicalizing([$this->businessA->id, $this->businessA->id, $this->businessB->id, $this->businessB->id],
            \DB::table('transactions')->where('type', 'CONSIGNMENT_BUYOUT_REVERSAL')->pluck('setting_id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame(2, ConsignmentBuyoutCostEvent::where('kind', 'REVERSAL')->count());
        $p = app(\Modules\Consignment\Services\ConsignmentTransferProvenanceService::class)->project($this->receipt->fresh());
        $this->assertEqualsWithDelta(0.0, $p['acquired'], 0.001, 'units are supplier-owned custody again');
    }

    /** @test */
    public function later_use_of_remotely_acquired_stock_blocks_reversal(): void
    {
        $this->grantActor(self::PERMS, [$this->businessA, $this->businessB]);
        $b = $this->life->approve($this->life->submit($this->draft(), $this->actor), $this->actor);
        app(ConsignmentBuyoutFinalizationService::class)->finalize($b->fresh(), $this->actor);
        // PERDANA consumes and replenishes at its standard destination (same balance afterwards).
        foreach ([-1, 1] as $q) {
            \Modules\Product\Entities\Transaction::create(['product_id' => $this->serialized->id, 'setting_id' => $this->businessB->id, 'type' => 'ADJ',
                'quantity' => $q, 'current_quantity' => 0, 'broken_quantity' => 0, 'location_id' => $this->b1->id, 'user_id' => $this->actor->id,
                'reason' => 'x', 'previous_quantity' => 0, 'after_quantity' => 0, 'previous_quantity_at_location' => 0, 'after_quantity_at_location' => 0,
                'quantity_tax' => 0, 'quantity_non_tax' => $q, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0]);
        }
        $preview = app(\Modules\Consignment\Services\ConsignmentBuyoutReversalService::class)->preview($b->fresh());
        $this->assertFalse($preview['eligible']);
        $this->assertContains('MOVEMENT', array_column($preview['blockers'], 'type'));
    }

    /** @test */
    public function acquired_remote_sold_units_return_to_the_selling_business(): void
    {
        $this->grantActor(self::PERMS, [$this->businessA, $this->businessB]);
        $dd = $this->sellAt($this->cb, [$this->sn[1]]);
        app(\Modules\Consignment\Services\ConsignmentSoldSourceDiscoveryService::class)->discoverForSetting($this->businessA->id);
        $src = \Modules\Consignment\Entities\ConsignmentSoldSource::where('dispatch_detail_id', $dd->id)->firstOrFail();
        $sold = fn ($dest) => ['line_type' => 'SOLD_UNBILLED', 'consignment_receiving_detail_id' => $this->receipt->id, 'consignment_sold_source_id' => $src->id,
            'destination_location_id' => $dest, 'base_quantity' => '1', 'serial_ids' => [$this->sn[1]->id]];
        $meta = ['invoice_metadata' => ['invoice_date' => now()->toDateString(), 'supplier_invoice_number' => 'S']];

        $wrong = $this->life->createDraft($this->businessA->id, $this->supplier->id, now()->toDateString(), [$sold($this->a1->id)], $this->actor, $meta);
        $this->assertStringContainsString('business holding the goods', implode(' ', $this->life->validateDocument($wrong)));

        $b = $this->life->createDraft($this->businessA->id, $this->supplier->id, now()->toDateString(), [$sold($this->b1->id)], $this->actor, $meta);
        $this->assertSame([], $this->life->validateDocument($b));
        $stock = \DB::table('product_stocks')->get()->toArray();
        $b = $this->life->approve($this->life->submit($b, $this->actor), $this->actor);
        $purchase = app(ConsignmentBuyoutFinalizationService::class)->finalize($b->fresh(), $this->actor);

        $this->assertSame((int) $this->businessA->id, (int) $purchase->setting_id);
        $this->assertEquals($stock, \DB::table('product_stocks')->get()->toArray(), 'sold slices move no stock');
        $lines = app(\Modules\Consignment\Services\ConsignmentBuyoutReturnOwnershipService::class)->finalizedSoldLines($src->fresh());
        $this->assertSame($this->b1->id, (int) $lines->first()->destination_location_id, 'a later PERDANA customer return is owned by PERDANA');
    }
}
