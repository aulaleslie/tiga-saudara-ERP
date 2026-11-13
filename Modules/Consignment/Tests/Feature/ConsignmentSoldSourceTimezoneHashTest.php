<?php

namespace Modules\Consignment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Consignment\Services\ConsignmentBillingConfirmationLifecycleService;
use Modules\Consignment\Tests\Support\BuildsBuyoutFixtures;
use Tests\TestCase;

class ConsignmentSoldSourceTimezoneHashTest extends TestCase
{
    use RefreshDatabase;
    use BuildsBuyoutFixtures;

    public function test_historical_offset_hashes_accept_only_equivalent_instants_and_unchanged_evidence(): void
    {
        $this->setUpBuyoutFixtures();
        $service = app(ConsignmentBillingConfirmationLifecycleService::class);

        foreach ([1, 2] as $version) {
            $source = $this->soldSource('1');
            $detail = $source->dispatchDetail()->firstOrFail();
            $snapshot = [
                'snapshot_version' => $version,
                'dispatched_at' => '2026-10-17T15:55:40+08:00',
                'inventory_classification' => 'EXPLICIT_INVENTORY',
                'used_historical_compatibility_rule' => false,
            ];
            DB::table('dispatch_details')->where('id', $detail->id)->update(['is_inventory_managed' => true]);
            DB::table('consignment_sold_sources')->where('id', $source->id)->update(['source_snapshot' => json_encode($snapshot)]);

            // Historical capture hashed the offset's wall time, while the database keeps the UTC time.
            DB::table('dispatches')->where('id', $detail->dispatch_id)->update(['created_at' => '2026-10-17 15:55:40']);
            $capturedHash = $service->computeCanonicalLiveHash($source->fresh());
            // The immutable capture column holds the same UTC value the database keeps for the dispatch.
            DB::table('consignment_sold_sources')->where('id', $source->id)->update(['source_hash' => $capturedHash, 'dispatched_at' => '2026-10-17 07:55:40']);
            DB::table('dispatches')->where('id', $detail->dispatch_id)->update(['created_at' => '2026-10-17 07:55:40']);

            $this->assertSame($capturedHash, $service->computeCanonicalLiveHash($source->fresh()));
            $this->assertSame($capturedHash, DB::table('consignment_sold_sources')->where('id', $source->id)->value('source_hash'));

            DB::table('dispatch_details')->where('id', $detail->id)->update(['dispatched_quantity' => 2]);
            $this->assertNotSame($capturedHash, $service->computeCanonicalLiveHash($source->fresh()));
            DB::table('dispatch_details')->where('id', $detail->id)->update(['dispatched_quantity' => 1]);

            DB::table('dispatches')->where('id', $detail->dispatch_id)->update(['created_at' => '2026-10-17 07:55:41']);
            $this->assertNotSame($capturedHash, $service->computeCanonicalLiveHash($source->fresh()));
            DB::table('dispatches')->where('id', $detail->dispatch_id)->update(['created_at' => '2026-10-17 07:55:40']);

            DB::table('dispatch_details')->where('id', $detail->id)->update(['serial_numbers' => json_encode(['ALTERED-SERIAL'])]);
            $this->assertNotSame($capturedHash, $service->computeCanonicalLiveHash($source->fresh()));
        }
    
    }

    public function test_unchanged_evidence_matches_directly(): void
    {
        $this->setUpBuyoutFixtures();
        $service = app(ConsignmentBillingConfirmationLifecycleService::class);
        $source = $this->soldSource('1');
        $detail = $source->dispatchDetail()->firstOrFail();
        DB::table('dispatch_details')->where('id', $detail->id)->update(['is_inventory_managed' => true]);
        DB::table('consignment_sold_sources')->where('id', $source->id)->update(['source_snapshot' => json_encode([
            'snapshot_version' => 2,
            'dispatched_at' => '2026-10-17T15:55:40+08:00',
            'inventory_classification' => 'EXPLICIT_INVENTORY',
            'used_historical_compatibility_rule' => false,
        ])]);
        DB::table('dispatches')->where('id', $detail->dispatch_id)->update(['created_at' => '2026-10-17 15:55:40']);
        $capturedHash = $service->computeCanonicalLiveHash($source->fresh());
        DB::table('consignment_sold_sources')->where('id', $source->id)->update(['source_hash' => $capturedHash, 'dispatched_at' => '2026-10-17 15:55:40']);

        $this->assertSame($capturedHash, $service->computeCanonicalLiveHash($source->fresh()));
    }

    public function test_exact_offset_edit_of_live_dispatch_time_is_rejected(): void
    {
        $this->setUpBuyoutFixtures();
        $service = app(ConsignmentBillingConfirmationLifecycleService::class);

        foreach ([1, 2] as $version) {
            $source = $this->soldSource('1');
            $detail = $source->dispatchDetail()->firstOrFail();
            DB::table('dispatch_details')->where('id', $detail->id)->update(['is_inventory_managed' => true]);
            DB::table('consignment_sold_sources')->where('id', $source->id)->update(['source_snapshot' => json_encode([
                'snapshot_version' => $version,
                'dispatched_at' => '2026-10-17T15:55:40+08:00',
                'inventory_classification' => 'EXPLICIT_INVENTORY',
                'used_historical_compatibility_rule' => false,
            ])]);

            // Normal capture: live, snapshot wall time and immutable source column all read 15:55:40.
            DB::table('dispatches')->where('id', $detail->dispatch_id)->update(['created_at' => '2026-10-17 15:55:40']);
            $capturedHash = $service->computeCanonicalLiveHash($source->fresh());
            DB::table('consignment_sold_sources')->where('id', $source->id)->update(['source_hash' => $capturedHash, 'dispatched_at' => '2026-10-17 15:55:40']);
            $snapshotBefore = DB::table('consignment_sold_sources')->where('id', $source->id)->value('source_snapshot');

            // Only the live dispatch time changes, by exactly the snapshot offset.
            DB::table('dispatches')->where('id', $detail->dispatch_id)->update(['created_at' => '2026-10-17 07:55:40']);

            $this->assertNotSame($capturedHash, $service->computeCanonicalLiveHash($source->fresh()));
            $this->assertSame($capturedHash, DB::table('consignment_sold_sources')->where('id', $source->id)->value('source_hash'));
            $this->assertSame($snapshotBefore, DB::table('consignment_sold_sources')->where('id', $source->id)->value('source_snapshot'));
            $this->assertSame('2026-10-17 15:55:40', (string) DB::table('consignment_sold_sources')->where('id', $source->id)->value('dispatched_at'));
        }
    }
}
