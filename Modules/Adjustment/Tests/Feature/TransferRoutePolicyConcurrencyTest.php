<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Transfer;
use Modules\Adjustment\Entities\TransferRoutePolicy;
use Modules\Adjustment\Services\TransferLifecycleService;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

class TransferRoutePolicyConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected TransferLifecycleService $lifecycleService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->lifecycleService = app(TransferLifecycleService::class);
    }

    public function test_approval_locks_participating_settings_before_snapshotting_pkp_status(): void
    {
        Tax::create(['name' => 'PPN 11%', 'value' => 11, 'is_active' => true, 'is_default' => true]);

        $originSetting = Setting::factory()->create(['is_pkp' => false]);
        $destSetting = Setting::factory()->create(['is_pkp' => true]);
        $origin = Location::create(['setting_id' => $originSetting->id, 'name' => 'Origin']);
        $destination = Location::create(['setting_id' => $destSetting->id, 'name' => 'Destination']);

        $transfer = $this->lifecycleService->createDraft($origin->id, $destination->id, Transfer::CONDITION_GOOD, [], $this->user->id);
        $transfer = $this->lifecycleService->submitDraft($transfer, $this->user->id);

        $approved = $this->lifecycleService->approve($transfer, $this->user->id, $origin->setting_id);

        $policy = TransferRoutePolicy::where('transfer_id', $approved->id)->firstOrFail();

        // The snapshot reflects the PKP status locked at approval time,
        // independent of any later setting mutation.
        $this->assertTrue($policy->destination_is_pkp);
        $this->assertEquals(TransferRoutePolicy::CLASSIFICATION_TAX, $policy->destination_classification);
    }

    public function test_approval_uses_freshly_locked_location_ownership_not_a_stale_preloaded_relation(): void
    {
        $originSetting = Setting::factory()->create(['is_pkp' => false]);
        $reassignedSetting = Setting::factory()->create(['is_pkp' => true]);
        $destSetting = Setting::factory()->create(['is_pkp' => false]);
        $origin = Location::create(['setting_id' => $originSetting->id, 'name' => 'Origin']);
        $destination = Location::create(['setting_id' => $destSetting->id, 'name' => 'Destination']);

        Tax::create(['name' => 'PPN 11%', 'value' => 11, 'is_active' => true, 'is_default' => true]);

        $transfer = $this->lifecycleService->createDraft($origin->id, $destination->id, Transfer::CONDITION_GOOD, [], $this->user->id);
        $transfer = $this->lifecycleService->submitDraft($transfer, $this->user->id);

        // Preload the transfer's origin location relationship (simulating a
        // caller holding a stale in-memory copy), then reassign the origin
        // location's ownership in the database directly, bypassing the
        // preloaded relation entirely. Approval must re-query and lock the
        // location row fresh, so the snapshot reflects the reassignment.
        $transfer->load('originLocation');
        $this->assertEquals((int) $originSetting->id, (int) $transfer->originLocation->setting_id);

        $origin->update(['setting_id' => $reassignedSetting->id]);

        $approved = $this->lifecycleService->approve($transfer, $this->user->id, $reassignedSetting->id);

        $policy = TransferRoutePolicy::where('transfer_id', $approved->id)->firstOrFail();

        $this->assertEquals((int) $reassignedSetting->id, (int) $policy->origin_setting_id);
        $this->assertTrue($policy->origin_is_pkp);
    }

    public function test_replaying_approval_creates_exactly_one_policy_for_the_revision(): void
    {
        $originSetting = Setting::factory()->create(['is_pkp' => false]);
        $destSetting = Setting::factory()->create(['is_pkp' => false]);
        $origin = Location::create(['setting_id' => $originSetting->id, 'name' => 'Origin']);
        $destination = Location::create(['setting_id' => $destSetting->id, 'name' => 'Destination']);

        $transfer = $this->lifecycleService->createDraft($origin->id, $destination->id, Transfer::CONDITION_GOOD, [], $this->user->id);
        $transfer = $this->lifecycleService->submitDraft($transfer, $this->user->id);

        // Approve once
        $approved = $this->lifecycleService->approve($transfer, $this->user->id, $origin->setting_id);

        $count = TransferRoutePolicy::where('transfer_id', $approved->id)
            ->where('transfer_revision', $approved->revision)
            ->count();

        $this->assertEquals(1, $count);
    }
}
