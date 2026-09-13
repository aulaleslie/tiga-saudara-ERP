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
use RuntimeException;
use Tests\TestCase;

class TransferRoutePolicyApprovalTest extends TestCase
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

    private function approveTransfer(Location $origin, Location $destination): Transfer
    {
        $transfer = $this->lifecycleService->createDraft(
            $origin->id,
            $destination->id,
            Transfer::CONDITION_GOOD,
            [],
            $this->user->id
        );

        $transfer = $this->lifecycleService->submitDraft($transfer, $this->user->id);

        return $this->lifecycleService->approve($transfer, $this->user->id, $origin->setting_id);
    }

    public function test_same_business_route_snapshots_preserve_with_no_return(): void
    {
        $setting = Setting::factory()->create(['is_pkp' => false]);
        $origin = Location::create(['setting_id' => $setting->id, 'name' => 'Origin']);
        $destination = Location::create(['setting_id' => $setting->id, 'name' => 'Destination']);

        $transfer = $this->approveTransfer($origin, $destination);

        $policy = TransferRoutePolicy::where('transfer_id', $transfer->id)->firstOrFail();
        $this->assertEquals(TransferRoutePolicy::CLASSIFICATION_PRESERVE, $policy->destination_classification);
        $this->assertFalse($policy->mandatory_return);
        $this->assertNull($policy->resolved_tax_id);
    }

    public function test_cross_business_non_pkp_to_non_pkp_snapshots_non_tax_with_no_return(): void
    {
        $originSetting = Setting::factory()->create(['is_pkp' => false]);
        $destSetting = Setting::factory()->create(['is_pkp' => false]);
        $origin = Location::create(['setting_id' => $originSetting->id, 'name' => 'Origin']);
        $destination = Location::create(['setting_id' => $destSetting->id, 'name' => 'Destination']);

        $transfer = $this->approveTransfer($origin, $destination);

        $policy = TransferRoutePolicy::where('transfer_id', $transfer->id)->firstOrFail();
        $this->assertEquals(TransferRoutePolicy::CLASSIFICATION_NON_TAX, $policy->destination_classification);
        $this->assertFalse($policy->mandatory_return);
        $this->assertNull($policy->resolved_tax_id);
    }

    public function test_cross_business_pkp_to_non_pkp_snapshots_non_tax_with_mandatory_return(): void
    {
        $originSetting = Setting::factory()->create(['is_pkp' => true]);
        $destSetting = Setting::factory()->create(['is_pkp' => false]);
        $origin = Location::create(['setting_id' => $originSetting->id, 'name' => 'Origin']);
        $destination = Location::create(['setting_id' => $destSetting->id, 'name' => 'Destination']);

        $transfer = $this->approveTransfer($origin, $destination);

        $policy = TransferRoutePolicy::where('transfer_id', $transfer->id)->firstOrFail();
        $this->assertEquals(TransferRoutePolicy::CLASSIFICATION_NON_TAX, $policy->destination_classification);
        $this->assertTrue($policy->mandatory_return);
        $this->assertNull($policy->resolved_tax_id);
    }

    public function test_cross_business_non_pkp_to_pkp_snapshots_tax_with_mandatory_return(): void
    {
        Tax::create(['name' => 'PPN 11%', 'value' => 11, 'is_active' => true, 'is_default' => true]);

        $originSetting = Setting::factory()->create(['is_pkp' => false]);
        $destSetting = Setting::factory()->create(['is_pkp' => true]);
        $origin = Location::create(['setting_id' => $originSetting->id, 'name' => 'Origin']);
        $destination = Location::create(['setting_id' => $destSetting->id, 'name' => 'Destination']);

        $transfer = $this->approveTransfer($origin, $destination);

        $policy = TransferRoutePolicy::where('transfer_id', $transfer->id)->firstOrFail();
        $this->assertEquals(TransferRoutePolicy::CLASSIFICATION_TAX, $policy->destination_classification);
        $this->assertTrue($policy->mandatory_return);
        $this->assertNotNull($policy->resolved_tax_id);
    }

    public function test_cross_business_pkp_to_pkp_snapshots_tax_with_mandatory_return(): void
    {
        Tax::create(['name' => 'PPN 11%', 'value' => 11, 'is_active' => true, 'is_default' => true]);

        $originSetting = Setting::factory()->create(['is_pkp' => true]);
        $destSetting = Setting::factory()->create(['is_pkp' => true]);
        $origin = Location::create(['setting_id' => $originSetting->id, 'name' => 'Origin']);
        $destination = Location::create(['setting_id' => $destSetting->id, 'name' => 'Destination']);

        $transfer = $this->approveTransfer($origin, $destination);

        $policy = TransferRoutePolicy::where('transfer_id', $transfer->id)->firstOrFail();
        $this->assertEquals(TransferRoutePolicy::CLASSIFICATION_TAX, $policy->destination_classification);
        $this->assertTrue($policy->mandatory_return);
        $this->assertNotNull($policy->resolved_tax_id);
    }

    public function test_post_approval_setting_drift_does_not_change_committed_policy(): void
    {
        $originSetting = Setting::factory()->create(['is_pkp' => false]);
        $destSetting = Setting::factory()->create(['is_pkp' => false]);
        $origin = Location::create(['setting_id' => $originSetting->id, 'name' => 'Origin']);
        $destination = Location::create(['setting_id' => $destSetting->id, 'name' => 'Destination']);

        $transfer = $this->approveTransfer($origin, $destination);
        $policy = TransferRoutePolicy::where('transfer_id', $transfer->id)->firstOrFail();

        // Destination business becomes PKP after approval
        $destSetting->update(['is_pkp' => true]);

        $policy->refresh();
        $this->assertEquals(TransferRoutePolicy::CLASSIFICATION_NON_TAX, $policy->destination_classification);
        $this->assertFalse($policy->mandatory_return);
    }

    public function test_approval_fails_atomically_when_pkp_destination_has_no_applicable_tax(): void
    {
        Tax::query()->delete();

        $originSetting = Setting::factory()->create(['is_pkp' => false]);
        $destSetting = Setting::factory()->create(['is_pkp' => true]);
        $origin = Location::create(['setting_id' => $originSetting->id, 'name' => 'Origin']);
        $destination = Location::create(['setting_id' => $destSetting->id, 'name' => 'Destination']);

        $transfer = $this->lifecycleService->createDraft(
            $origin->id,
            $destination->id,
            Transfer::CONDITION_GOOD,
            [],
            $this->user->id
        );
        $transfer = $this->lifecycleService->submitDraft($transfer, $this->user->id);

        $this->expectException(RuntimeException::class);

        try {
            $this->lifecycleService->approve($transfer, $this->user->id, $origin->setting_id);
        } finally {
            $transfer->refresh();
            $this->assertEquals(Transfer::STATUS_PENDING, $transfer->status);
            $this->assertFalse(TransferRoutePolicy::where('transfer_id', $transfer->id)->exists());
        }
    }
}
