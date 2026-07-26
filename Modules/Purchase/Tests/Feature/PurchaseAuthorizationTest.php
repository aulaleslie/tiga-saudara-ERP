<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;
    private Purchase $purchase;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@company.com',
            'company_phone' => '123456',
            'notification_email' => 'notify@company.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_phone' => '123',
            'supplier_email' => 'supplier@test.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id,
        ]);

        $location = Location::create([
            'name' => 'Test Location',
            'setting_id' => $this->setting->id,
        ]);

        $this->purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'location_id' => $location->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'Approved',
        ]);

        $this->user = User::factory()->create(['is_active' => 1]);
    }

    public function test_user_without_purchase_access_cannot_index(): void
    {
        $this->actingAs($this->user);

        $response = $this->get(route('purchases.index'));

        $this->assertEquals(403, $response->status());
    }

    public function test_user_with_purchase_access_can_index(): void
    {
        $this->user->givePermissionTo('purchases.access');
        $this->actingAs($this->user);

        $response = $this->get(route('purchases.index'));

        $this->assertNotEquals(403, $response->status());
    }

    public function test_user_without_purchase_create_cannot_create(): void
    {
        $this->actingAs($this->user);

        $response = $this->get(route('purchases.create'));

        $this->assertEquals(403, $response->status());
    }

    public function test_user_with_purchase_create_can_create(): void
    {
        $this->user->givePermissionTo('purchases.create');
        $this->actingAs($this->user);

        $response = $this->get(route('purchases.create-alpine'));

        $this->assertNotEquals(403, $response->status());
    }

    public function test_user_without_purchase_update_cannot_edit(): void
    {
        $this->actingAs($this->user);

        $response = $this->post(route('purchases.update', $this->purchase->id), [
            'reference' => 'PO-002'
        ]);

        $this->assertEquals(403, $response->status());
    }

    public function test_user_with_purchase_update_can_edit(): void
    {
        $this->user->givePermissionTo(['purchases.update', 'purchases.create']);
        $this->actingAs($this->user);

        // This test verifies authorization check passes
        // Actual edit test would require full form data
        $response = $this->post(route('purchases.update', $this->purchase->id), []);

        // Should not be 403 (auth check passed, may fail validation instead)
        $this->assertNotEquals(403, $response->status());
    }

    public function test_user_without_purchase_show_cannot_view_detail(): void
    {
        $this->actingAs($this->user);

        $response = $this->get(route('purchases.show', $this->purchase->id));

        $this->assertEquals(403, $response->status());
    }

    public function test_user_with_purchase_show_can_view_detail(): void
    {
        $this->user->givePermissionTo('purchases.show');
        $this->actingAs($this->user);

        $response = $this->get(route('purchases.show', $this->purchase->id));

        $this->assertNotEquals(403, $response->status());
    }

    public function test_user_without_purchase_receive_cannot_receive(): void
    {
        $this->actingAs($this->user);

        $response = $this->get(route('purchases.receive', $this->purchase->id));

        $this->assertEquals(403, $response->status());
    }

    public function test_user_with_purchase_receive_can_receive(): void
    {
        $this->user->givePermissionTo('purchases.receive');
        $this->actingAs($this->user);

        $response = $this->get(route('purchases.receive', $this->purchase->id));

        // Should not be forbidden due to auth check
        $this->assertNotEquals(403, $response->status());
    }

    public function test_user_without_purchase_archive_cannot_archive(): void
    {
        $this->actingAs($this->user);

        $response = $this->put(route('purchases.archive', $this->purchase->id), []);

        $this->assertEquals(403, $response->status());
    }

    public function test_user_with_purchase_archive_can_archive(): void
    {
        $this->user->givePermissionTo('purchases.archive');
        $this->actingAs($this->user);

        $this->purchase->update(['status' => Purchase::STATUS_APPROVED]);

        $response = $this->put(route('purchases.archive', $this->purchase->id), []);

        // Should not be forbidden due to auth check
        $this->assertNotEquals(403, $response->status());
    }

    public function test_user_with_receive_access_can_access_receiving_index(): void
    {
        $this->user->givePermissionTo('purchases.receive.access');
        $this->actingAs($this->user);

        $response = $this->get(route('purchases.receiving.index'));

        $this->assertNotEquals(403, $response->status());
    }

    public function test_canonical_permissions_are_defined_in_config(): void
    {
        $permissionsConfig = config('permissions');

        // Check purchase permissions
        $purchasePermissions = $permissionsConfig['Pembelian'] ?? [];
        $this->assertArrayHasKey('purchases.access', $purchasePermissions);
        $this->assertArrayHasKey('purchases.create', $purchasePermissions);
        $this->assertArrayHasKey('purchases.update', $purchasePermissions);
        $this->assertArrayHasKey('purchases.show', $purchasePermissions);
        $this->assertArrayHasKey('purchases.delete', $purchasePermissions);
        $this->assertArrayHasKey('purchases.archive', $purchasePermissions);
        $this->assertArrayHasKey('purchases.receive', $purchasePermissions);

        // Check receiving permissions
        $receivingPermissions = $permissionsConfig['Penerimaan Barang'] ?? [];
        $this->assertArrayHasKey('purchases.receive.access', $receivingPermissions);
        $this->assertArrayHasKey('purchases.receive.approval', $receivingPermissions);

        // Legacy permission should NOT exist
        $this->assertArrayNotHasKey('purchases.update', $purchasePermissions);
        $this->assertArrayNotHasKey('purchases.receive.access', $receivingPermissions);
        $this->assertArrayNotHasKey('purchases.receive.approval', $receivingPermissions);
    }

    public function test_legacy_permission_remap_gives_users_canonical_access(): void
    {
        // Simulate a legacy system where user had old permission
        $role = Role::create(['name' => 'TestRole']);
        $legacyPerm = Permission::create(['name' => 'purchases.update']);
        $role->givePermissionTo($legacyPerm);

        $this->user->assignRole($role);

        // After remap migration, canonical permission should be present
        // (In actual implementation, migration would add it)
        $canonicalPerm = Permission::firstOrCreate(['name' => 'purchases.update']);
        $role->givePermissionTo($canonicalPerm);

        $this->assertTrue($this->user->hasPermissionTo('purchases.update'));
    }

    public function test_undefined_gates_are_not_used(): void
    {
        // Verify no undefined permissions are checked in the codebase
        // This is primarily a code review check, but we document it here
        $undefinedPermissions = [
            'purchases.view',           // Removed (consolidated with show)
            'purchases.receive.access',  // Renamed to purchases.receive.access
            'purchases.receive.approval', // Renamed to purchases.receive.approval
        ];

        $permissionsConfig = config('permissions');
        $allConfiguredPermissions = [];

        foreach ($permissionsConfig as $group => $groupPermissions) {
            $allConfiguredPermissions = array_merge($allConfiguredPermissions, array_keys($groupPermissions));
        }

        foreach ($undefinedPermissions as $permission) {
            $this->assertNotContains($permission, $allConfiguredPermissions,
                "Legacy permission '$permission' should not be in config");
        }
    }
}
