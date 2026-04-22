<?php

namespace Modules\PurchasesReturn\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PurchaseReturnAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;
    private PurchaseReturn $purchaseReturn;
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

        $category = Category::create([
            'category_code' => 'TEST',
            'category_name' => 'Test Category',
            'setting_id' => $this->setting->id,
            'created_by' => 1,
        ]);

        $product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_quantity' => 10,
            'product_unit' => 'pcs',
            'category_id' => $category->id,
            'setting_id' => $this->setting->id,
        ]);

        $this->purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PR-001',
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
            'status' => PurchaseReturn::STATUS_COMPLETED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'Approved',
        ]);

        PurchaseReturnDetail::create([
            'purchase_return_id' => $this->purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 5,
            'price' => 1000,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'sub_total' => 5000,
        ]);

        $this->user = User::factory()->create(['is_active' => 1]);
    }

    public function test_user_without_purchase_return_access_cannot_index(): void
    {
        $this->actingAs($this->user);

        $response = $this->get(route('purchase-returns.index'));

        $this->assertEquals(403, $response->status());
    }

    public function test_user_with_purchase_return_access_can_index(): void
    {
        $this->user->givePermissionTo('purchaseReturns.access');
        $this->actingAs($this->user);

        $response = $this->get(route('purchase-returns.index'));

        $this->assertNotEquals(403, $response->status());
    }

    public function test_user_without_purchase_return_create_cannot_create(): void
    {
        $this->actingAs($this->user);

        $response = $this->get(route('purchase-returns.create'));

        $this->assertEquals(403, $response->status());
    }

    public function test_user_with_purchase_return_create_can_create(): void
    {
        $this->user->givePermissionTo('purchaseReturns.create');
        $this->actingAs($this->user);

        $response = $this->get(route('purchase-returns.create'));

        $this->assertNotEquals(403, $response->status());
    }

    public function test_user_without_purchase_return_update_cannot_edit(): void
    {
        $this->actingAs($this->user);

        $response = $this->post(route('purchase-returns.update', $this->purchaseReturn->id), []);

        $this->assertEquals(403, $response->status());
    }

    public function test_user_with_purchase_return_update_can_edit(): void
    {
        $this->user->givePermissionTo('purchaseReturns.update');
        $this->actingAs($this->user);

        $response = $this->post(route('purchase-returns.update', $this->purchaseReturn->id), []);

        // May fail validation but not auth
        $this->assertNotEquals(403, $response->status());
    }

    public function test_user_without_purchase_return_show_cannot_view_detail(): void
    {
        $this->actingAs($this->user);

        $response = $this->get(route('purchase-returns.show', $this->purchaseReturn->id));

        $this->assertEquals(403, $response->status());
    }

    public function test_user_with_purchase_return_show_can_view_detail(): void
    {
        $this->user->givePermissionTo('purchaseReturns.show');
        $this->actingAs($this->user);

        $response = $this->get(route('purchase-returns.show', $this->purchaseReturn->id));

        $this->assertNotEquals(403, $response->status());
    }

    public function test_user_without_purchase_return_archive_cannot_archive(): void
    {
        $this->actingAs($this->user);

        $response = $this->put(route('purchase-returns.archive', $this->purchaseReturn->id), []);

        $this->assertEquals(403, $response->status());
    }

    public function test_user_with_purchase_return_archive_can_archive(): void
    {
        $this->user->givePermissionTo('purchaseReturns.archive');
        $this->actingAs($this->user);

        $response = $this->put(route('purchase-returns.archive', $this->purchaseReturn->id), []);

        $this->assertNotEquals(403, $response->status());
    }

    public function test_user_without_purchase_return_approval_cannot_approve(): void
    {
        $this->purchaseReturn->update(['status' => PurchaseReturn::STATUS_PENDING_APPROVAL]);
        $this->actingAs($this->user);

        $response = $this->post(route('purchase-returns.approve', $this->purchaseReturn->id), []);

        $this->assertEquals(403, $response->status());
    }

    public function test_user_with_purchase_return_approval_can_approve(): void
    {
        $this->purchaseReturn->update(['status' => PurchaseReturn::STATUS_PENDING_APPROVAL]);
        $this->user->givePermissionTo('purchaseReturns.approval');
        $this->actingAs($this->user);

        $response = $this->post(route('purchase-returns.approve', $this->purchaseReturn->id), []);

        $this->assertNotEquals(403, $response->status());
    }

    public function test_canonical_permissions_are_defined_for_purchase_returns(): void
    {
        $permissionsConfig = config('permissions');

        $returnPermissions = $permissionsConfig['Retur Pembelian'] ?? [];
        $this->assertArrayHasKey('purchaseReturns.access', $returnPermissions);
        $this->assertArrayHasKey('purchaseReturns.create', $returnPermissions);
        $this->assertArrayHasKey('purchaseReturns.update', $returnPermissions);
        $this->assertArrayHasKey('purchaseReturns.show', $returnPermissions);
        $this->assertArrayHasKey('purchaseReturns.delete', $returnPermissions);
        $this->assertArrayHasKey('purchaseReturns.archive', $returnPermissions);
        $this->assertArrayHasKey('purchaseReturns.approval', $returnPermissions);

        // Legacy permission should NOT exist
        $this->assertArrayNotHasKey('purchaseReturns.edit', $returnPermissions);
    }

    public function test_canonical_permissions_are_independent_of_settlement_permissions(): void
    {
        $permissionsConfig = config('permissions');

        $returnPermissions = $permissionsConfig['Retur Pembelian'] ?? [];
        $settlementPermissions = $permissionsConfig['Penyelesaian Retur Pembelian'] ?? [];

        // These should be separate namespaces
        $this->assertArrayHasKey('purchaseReturns.approval', $returnPermissions);
        $this->assertArrayHasKey('purchaseReturnSettlements.approve', $settlementPermissions);

        // Should not merge receive operations
        $this->assertArrayNotHasKey('purchaseReturns.receive', $returnPermissions);
        $this->assertArrayHasKey('purchaseReturnSettlements.receive', $settlementPermissions);
    }

    public function test_price_visibility_permission_is_independent(): void
    {
        $permissionsConfig = config('permissions');
        $returnPermissions = $permissionsConfig['Retur Pembelian'] ?? [];

        // viewPrice is a special permission independent of CRUD operations
        $this->assertArrayHasKey('purchaseReturns.viewPrice', $returnPermissions);

        // User without show permission but with viewPrice should see prices
        $this->user->givePermissionTo('purchaseReturns.viewPrice');
        $this->assertTrue($this->user->hasPermissionTo('purchaseReturns.viewPrice'));
    }
}
