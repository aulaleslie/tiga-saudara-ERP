<?php

namespace Modules\Adjustment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Adjustment\Entities\Transfer;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TransferLifecycleFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected User $creator;
    protected User $originEditor;
    protected User $nonOriginEditor;
    protected Setting $originSetting;
    protected Setting $destSetting;
    protected Location $originLocation;
    protected Location $destLocation;
    protected Transfer $transfer;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake(); // Prevent actual events/notifications
        $this->withoutMiddleware([\App\Http\Middleware\CheckUserRoleForSetting::class]);

        // Set up roles and permissions
        $editPermission = Permission::firstOrCreate(['name' => 'stockTransfers.edit']);
        $approvePermission = Permission::firstOrCreate(['name' => 'stockTransfers.approval']);
        $archivePermission = Permission::firstOrCreate(['name' => 'stockTransfers.archive']);
        
        $roleCreator = Role::firstOrCreate(['name' => 'CreatorRole']);
        $roleCreator->givePermissionTo($editPermission, $approvePermission, $archivePermission);

        $roleEditor = Role::firstOrCreate(['name' => 'EditorRole']);
        $roleEditor->givePermissionTo($editPermission); // Editors can edit but not approve

        $roleApprover = Role::firstOrCreate(['name' => 'ApproverRole']);
        $roleApprover->givePermissionTo($editPermission, $approvePermission);

        // Tenants
        $this->originSetting = Setting::factory()->create(['company_name' => 'Origin Tenant']);
        $this->destSetting = Setting::factory()->create(['company_name' => 'Dest Tenant']);

        // Locations
        $this->originLocation = Location::create(['name' => 'Origin Loc', 'setting_id' => $this->originSetting->id]);
        $this->destLocation = Location::create(['name' => 'Dest Loc', 'setting_id' => $this->destSetting->id]);

        // Users
        $this->creator = User::factory()->create();
        $this->creator->assignRole('CreatorRole');

        $this->originEditor = User::factory()->create();
        $this->originEditor->assignRole('EditorRole');

        $this->nonOriginEditor = User::factory()->create();
        $this->nonOriginEditor->assignRole('ApproverRole');

        // Initial Transfer
        $this->transfer = Transfer::create([
            'origin_location_id' => $this->originLocation->id,
            'destination_location_id' => $this->destLocation->id,
            'created_by' => $this->creator->id,
            'status' => Transfer::STATUS_PENDING,
            'revision' => 2, // Assume created as draft(1) then submitted(2)
        ]);
    }

    public function test_creator_self_approval()
    {
        // Must be origin tenant
        session(['setting_id' => $this->originSetting->id]);
        
        $response = $this->actingAs($this->creator)->post(route('transfers.approve', $this->transfer));
        $response->assertRedirect(route('transfers.show', $this->transfer));

        $this->assertDatabaseHas('transfers', [
            'id' => $this->transfer->id,
            'status' => Transfer::STATUS_APPROVED,
        ]);
    }

    public function test_editor_denial_for_approval()
    {
        // Editor doesn't have stockTransfers.approval
        session(['setting_id' => $this->originSetting->id]);
        
        $response = $this->actingAs($this->originEditor)->post(route('transfers.approve', $this->transfer));
        $response->assertStatus(403);
    }

    public function test_non_origin_denial()
    {
        // Non-origin user tries to approve
        session(['setting_id' => $this->destSetting->id]);
        
        $response = $this->actingAs($this->nonOriginEditor)->post(route('transfers.approve', $this->transfer));
        $response->assertRedirect(route('transfers.show', $this->transfer));

        $this->assertDatabaseHas('transfers', [
            'id' => $this->transfer->id,
            'status' => Transfer::STATUS_PENDING,
        ]);
    }

    public function test_rejection_acknowledgement()
    {
        // First reject it
        $this->transfer->update(['status' => Transfer::STATUS_REJECTED]);

        session(['setting_id' => $this->originSetting->id]);
        
        $response = $this->actingAs($this->creator)->post(route('transfers.acknowledge-rejection', $this->transfer));
        $response->assertRedirect(route('transfers.show', $this->transfer));

        $this->assertDatabaseHas('transfers', [
            'id' => $this->transfer->id,
            'status' => Transfer::STATUS_DRAFT,
        ]);
    }

    public function test_archive_rules()
    {
        // Can archive APPROVED (not DRAFT)
        $this->transfer->update(['status' => Transfer::STATUS_APPROVED]);
        
        session(['setting_id' => $this->originSetting->id]);

        $response = $this->actingAs($this->creator)->post(route('transfers.archive', $this->transfer), [
            'reason' => 'Duplicate transfer'
        ]);
        $response->assertRedirect();
        
        $this->assertDatabaseHas('transfers', [
            'id' => $this->transfer->id,
            'status' => Transfer::STATUS_ARCHIVED,
            'archive_reason' => 'DUPLICATE TRANSFER'
        ]);
    }

    public function test_approved_immutability()
    {
        // Need a product to pass validation
        $productId = \Illuminate\Support\Facades\DB::table('products')->insertGetId([
            'product_name' => 'Test',
            'product_code' => 'TEST-01',
            'product_price' => 0,
            'product_cost' => 0,
            'product_unit' => 'pc',
            'product_barcode_symbology' => 'C128',
            'product_quantity' => 0,
            'setting_id' => $this->originSetting->id,
        ]);

        $this->transfer->update(['status' => Transfer::STATUS_APPROVED]);

        session(['setting_id' => $this->originSetting->id]);

        // Attempt to edit (resubmit) an approved transfer should fail
        $response = $this->actingAs($this->creator)->post(route('transfers.resubmit', $this->transfer), [
            'product_ids' => [$productId],
            'quantities' => [10],
            'origin_location' => $this->originLocation->id,
            'destination_location' => $this->destLocation->id,
        ]);
        
        // It throws an exception which is caught and redirects back
        $response->assertRedirect(route('transfers.show', $this->transfer));

        $this->assertDatabaseHas('transfers', [
            'id' => $this->transfer->id,
            'status' => Transfer::STATUS_APPROVED,
        ]);
    }
}
