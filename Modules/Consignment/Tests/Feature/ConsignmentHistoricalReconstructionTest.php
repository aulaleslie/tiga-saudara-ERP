<?php

namespace Modules\Consignment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Consignment\Entities\ConsignmentHistoricalMapping;
use Modules\Consignment\Entities\ConsignmentProvenanceEvent as Event;
use Modules\Consignment\Services\ConsignmentHistoricalReconstructionService;
use Modules\Consignment\Services\ConsignmentTransferProvenanceService;
use Modules\Consignment\Tests\Support\BuildsRemoteProvenanceFixtures;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** 7.1: preview-first, confirmed reconstruction of pre-provenance V3 consignment transfers. */
class ConsignmentHistoricalReconstructionTest extends TestCase
{
    use RefreshDatabase;
    use BuildsRemoteProvenanceFixtures;

    private ConsignmentHistoricalReconstructionService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRemoteFixtures();
        $this->svc = app(ConsignmentHistoricalReconstructionService::class);
        Permission::findOrCreate('consignments.provenance.reconcile', 'web');
        $this->actor->givePermissionTo('consignments.provenance.reconcile');
        $this->actor->settings()->syncWithoutDetaching([$this->businessA->id => ['role_id' => Role::findOrCreate('HR', 'web')->id]]);
    }

    /** Executes real transfers, then erases their provenance to reproduce data created before this change. */
    private function preProvenanceMoves(): void
    {
        $this->moveSerial($this->sn[1], $this->ca, $this->cb);
        $this->moveSerial($this->sn[2], $this->ca, $this->cb);
        $this->moveSerial($this->sn[2], $this->cb, $this->ca);   // multi-hop back to origin
        \DB::table('consignment_provenance_events')->delete();
    }

    private function snapshot(): array
    {
        return array_map(fn ($t) => \DB::table($t)->orderBy('id')->get()->toArray(),
            ['product_stocks', 'product_serial_numbers', 'transfers', 'transfer_movement_allocations', 'sales', 'purchases', 'purchase_payments']);
    }

    /** @test */
    public function preview_writes_nothing_and_confirm_rebuilds_exact_custody_once(): void
    {
        $this->preProvenanceMoves();
        $before = $this->snapshot();

        $p = $this->svc->preview($this->serialized->id);
        $this->assertSame(0, Event::count(), 'preview writes nothing');
        $this->assertSame(0, ConsignmentHistoricalMapping::count());
        $this->assertSame([], $p['blockers']);
        $this->assertCount(6, $p['events'], 'three legs x dispatch+receipt');

        $this->svc->confirm($this->serialized->id, $p['evidence_hash'], 'Rekonstruksi AXIOO', $this->actor);
        $this->svc->confirm($this->serialized->id, $p['evidence_hash'], 'Rekonstruksi AXIOO', $this->actor);   // repeat
        $this->assertSame(6, Event::count());
        $this->assertSame(1, ConsignmentHistoricalMapping::count());
        $this->assertEquals($before, $this->snapshot(), 'no stock, serial, transfer, sale, purchase or payment change');

        $prov = app(ConsignmentTransferProvenanceService::class);
        $this->assertSame(['state' => 'SELLABLE', 'location_id' => $this->cb->id], $prov->serialCustody($this->sn[1]->fresh()));
        $this->assertSame(['state' => 'SELLABLE', 'location_id' => $this->ca->id], $prov->serialCustody($this->sn[2]->fresh()));

        // Afterwards the preview is empty: every leg is mapped.
        $this->assertSame([], $this->svc->preview($this->serialized->id)['events']);
    }

    /** @test */
    public function stale_hash_missing_reason_or_permission_are_refused(): void
    {
        $this->preProvenanceMoves();
        $p = $this->svc->preview($this->serialized->id);
        foreach ([fn () => $this->svc->confirm($this->serialized->id, str_repeat('0', 64), 'x', $this->actor),
            fn () => $this->svc->confirm($this->serialized->id, $p['evidence_hash'], ' ', $this->actor)] as $call) {
            try {
                $call();
                $this->fail('accepted');
            } catch (\DomainException|\InvalidArgumentException $e) {
            }
        }
        $other = \App\Models\User::factory()->create();
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->svc->confirm($this->serialized->id, $p['evidence_hash'], 'x', $other);
    }

    /** @test */
    public function reconstructed_custody_lets_manual_discovery_bind_a_remote_sale(): void
    {
        $this->preProvenanceMoves();
        $dd = $this->sellAt($this->cb, [$this->sn[1]]);
        $this->discover($this->businessA);
        $this->assertSame(0, \Modules\Consignment\Entities\ConsignmentObligationBinding::count(), 'unmapped history stays a blocker');

        $p = $this->svc->preview($this->serialized->id);
        $this->svc->confirm($this->serialized->id, $p['evidence_hash'], 'Rekonstruksi', $this->actor);
        $this->discover($this->businessA);
        $this->assertSame(1, \Modules\Consignment\Entities\ConsignmentObligationBinding::count());
        $this->assertTrue($this->perReceipt($dd)['complete']);
    }

    private function bulkTransfer($from, $to, int $qty, array $lots, bool $receive = true, bool $cancel = false): \Modules\Adjustment\Entities\Transfer
    {
        $setting = (int) $from->setting_id;
        session(['setting_id' => $setting]);
        $t = app(\Modules\Adjustment\Services\TransferV3GoodsService::class)->createAndSubmit(\Modules\Adjustment\Entities\Transfer::CONDITION_GOOD,
            [['product_id' => $this->bulk->id, 'quantity' => $qty, 'serial_ids' => []]], $this->actor, $setting);
        $t->refresh();
        app(\Modules\Adjustment\Services\TransferV3AllocationService::class)->saveProgress($t, $this->actor, $setting, (int) $t->current_request_revision_id,
            (int) $t->approval_configuration_revision, [], [['product_id' => $this->bulk->id, 'source_location_id' => $from->id, 'destination_location_id' => $to->id, 'quantity' => $qty]]);
        $row = app(\Modules\Adjustment\Services\TransferV3AllocationService::class)->currentPlan($t->fresh())->first()->id;
        app(ConsignmentTransferProvenanceService::class)->confirmSelection($t, [$row => $lots], $this->actor);
        $t->refresh();
        app(\Modules\Adjustment\Services\TransferV3DispatchExecutor::class)->approveAndDispatch($t, $this->actor, $setting,
            (int) $t->current_request_revision_id, (int) $t->approval_configuration_revision);
        if ($cancel) {
            app(\Modules\Adjustment\Services\TransferV3CancellationExecutor::class)->cancel($t->fresh(), $this->actor, $setting, 'Batal');
        } elseif ($receive) {
            app(\Modules\Adjustment\Services\TransferV3ReceiptExecutor::class)->receive($t->fresh(), $this->actor, (int) $to->setting_id);
        }

        return $t;
    }

    private function sellable($d, $loc): float
    {
        return (new \Modules\Consignment\Services\ConsignmentCustodyProjection)->quantityAt(app(ConsignmentTransferProvenanceService::class)->project($d->fresh()), 'SELLABLE', $loc->id);
    }

    /** @test */
    public function non_serialized_sole_lot_history_with_cancellation_and_onward_return_is_proposed_and_confirmed(): void
    {
        $lot = $this->bulkReceipt(5);
        $this->stock($this->bulk, $this->ca, nonTax: 5);
        \DB::table('products')->where('id', $this->bulk->id)->update(['product_quantity' => 100]);
        $this->bulkTransfer($this->ca, $this->cb, 3, [$lot->id => 3]);
        $this->bulkTransfer($this->ca, $this->cb, 1, [$lot->id => 1], cancel: true);
        $this->bulkTransfer($this->cb, $this->ca, 1, [$lot->id => 1]);   // onward/return to origin
        \DB::table('consignment_provenance_events')->delete();

        $p = $this->svc->preview($this->bulk->id);
        $this->assertSame([], $p['blockers'], 'every leg has a sole fitting lot');
        $this->assertSame(0, Event::count());
        $this->svc->confirm($this->bulk->id, $p['evidence_hash'], 'Lot tunggal', $this->actor);

        $this->assertEqualsWithDelta(3.0, $this->sellable($lot, $this->ca), 0.001);
        $this->assertEqualsWithDelta(2.0, $this->sellable($lot, $this->cb), 0.001);
        $this->assertTrue(app(ConsignmentTransferProvenanceService::class)->project($lot->fresh())['conserved']);
    }

    /** @test */
    public function ambiguous_mixed_lot_history_stays_blocked_until_explicitly_mapped(): void
    {
        $l1 = $this->bulkReceipt(2);
        $l2 = $this->bulkReceipt(2);
        $this->stock($this->bulk, $this->ca, nonTax: 4);
        \DB::table('products')->where('id', $this->bulk->id)->update(['product_quantity' => 100]);
        $t = $this->bulkTransfer($this->ca, $this->cb, 3, [$l1->id => 2, $l2->id => 1]);
        \DB::table('consignment_provenance_events')->delete();
        $alloc = (int) \Modules\Adjustment\Entities\TransferMovementAllocation::where('transfer_id', $t->id)->where('kind', 'DISPATCH')->value('id');

        $p = $this->svc->preview($this->bulk->id);
        $this->assertStringContainsString('explicit quantities are required', implode(' ', $p['blockers']));
        $this->assertSame([], $p['events'], 'no FIFO/LIFO guess');
        $this->svc->confirm($this->bulk->id, $p['evidence_hash'], 'nothing resolvable yet', $this->actor);
        $this->assertSame(0, Event::count(), 'unresolved evidence is never repaired automatically');

        $bad = $this->svc->preview($this->bulk->id, [$alloc => [$l1->id => 3]]);
        $this->assertNotEmpty($bad['blockers'], 'a lot cannot give more than it held');

        $good = $this->svc->preview($this->bulk->id, [$alloc => [$l1->id => 2, $l2->id => 1]]);
        $this->assertSame([], $good['blockers']);
        $this->svc->confirm($this->bulk->id, $good['evidence_hash'], 'Dipetakan operator', $this->actor, [$alloc => [$l1->id => 2, $l2->id => 1]]);
        $this->assertEqualsWithDelta(2.0, $this->sellable($l1, $this->cb), 0.001);
        $this->assertEqualsWithDelta(1.0, $this->sellable($l2, $this->cb), 0.001);
        $this->assertEqualsWithDelta(1.0, $this->sellable($l2, $this->ca), 0.001);
    }

    /** Review P2 regression: an idempotent retry re-checks permission and origin scope. */
    public function test_retry_rechecks_authorization_and_origin_scope(): void
    {
        $this->preProvenanceMoves();
        $p = $this->svc->preview($this->serialized->id);
        $this->svc->confirm($this->serialized->id, $p['evidence_hash'], 'Rekonstruksi', $this->actor);

        // Unauthorized retry with the same hash: refused, nothing disclosed.
        $stranger = \App\Models\User::factory()->create();
        try {
            $this->svc->confirm($this->serialized->id, $p['evidence_hash'], 'retry', $stranger);
            $this->fail('unauthorized retry returned stored mappings');
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
        }

        // A reconciler of ANOTHER business only: refused for a wrong-business filter, and the origin's mappings
        // never leak through a filter that does not match them.
        $other = $this->businessB;
        $foreign = \App\Models\User::factory()->create();
        $foreign->givePermissionTo('consignments.provenance.reconcile');
        $foreign->settings()->attach($other->id, ['role_id' => Role::findOrCreate('HR', 'web')->id]);
        try {
            $this->svc->confirm($this->serialized->id, $p['evidence_hash'], 'retry', $foreign, [], $this->businessA->id);
            $this->fail('wrong-business retry returned stored mappings');
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
        }
        try {
            $out = $this->svc->confirm($this->serialized->id, $p['evidence_hash'], 'retry', $foreign, [], $other->id);
            $this->fail('a stale hash outside the scope must not resolve to the origin mappings');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('evidence changed', $e->getMessage());
        }

        // The authorized origin reconciler still gets the idempotent result, scoped or not.
        $this->assertCount(1, $this->svc->confirm($this->serialized->id, $p['evidence_hash'], 'retry', $this->actor));
        $this->assertCount(1, $this->svc->confirm($this->serialized->id, $p['evidence_hash'], 'retry', $this->actor, [], $this->businessA->id));
        $this->assertSame(1, ConsignmentHistoricalMapping::count());
        $this->assertSame(6, Event::count());
    }
}
