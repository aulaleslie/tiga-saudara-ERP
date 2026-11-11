<?php

namespace Tests\Feature\Reports;

use App\Livewire\Reports\StockInsights;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockInsightsBusinessScopeTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $settingA;
    protected Setting $settingB;
    protected Setting $settingC;
    protected Location $locationA;
    protected Location $locationB;
    protected Location $locationC;
    protected Role $adminRole;
    protected Role $superAdminRole;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        Permission::findOrCreate('stockInsights.access', 'web');

        $this->settingA = Setting::factory()->create(['company_name' => 'Bisnis A']);
        $this->settingB = Setting::factory()->create(['company_name' => 'Bisnis B']);
        $this->settingC = Setting::factory()->create(['company_name' => 'Bisnis C']);

        $this->locationA = Location::create([
            'setting_id' => $this->settingA->id,
            'name' => 'Gudang A',
            'is_active' => true,
        ]);
        $this->locationB = Location::create([
            'setting_id' => $this->settingB->id,
            'name' => 'Gudang B',
            'is_active' => true,
        ]);
        $this->locationC = Location::create([
            'setting_id' => $this->settingC->id,
            'name' => 'Gudang C',
            'is_active' => true,
        ]);

        $this->adminRole = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $this->superAdminRole = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        $this->product = Product::forceCreate([
            'setting_id' => $this->settingA->id,
            'product_name' => 'Item Scope Test',
            'product_code' => 'IST-001',
            'product_cost' => 10000,
            'product_price' => 20000,
            'product_stock_alert' => 5,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        // Stock at locations:
        // A: 10
        // B: 20
        // C: 50
        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->locationA->id,
            'quantity' => 10,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->locationB->id,
            'quantity' => 20,
            'quantity_tax' => 20,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->locationC->id,
            'quantity' => 50,
            'quantity_tax' => 50,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Sales in rolling period (today)
        $today = Carbon::today()->format('Y-m-d');
        $customerA = Customer::factory()->create(['setting_id' => $this->settingA->id]);
        $customerB = Customer::factory()->create(['setting_id' => $this->settingB->id]);
        $customerC = Customer::factory()->create(['setting_id' => $this->settingC->id]);

        $this->createCompletedSale($this->settingA->id, $customerA, $today, 1, 100000);
        $this->createCompletedSale($this->settingB->id, $customerB, $today, 2, 200000);
        $this->createCompletedSale($this->settingC->id, $customerC, $today, 5, 500000);
    }

    protected function createCompletedSale(int $settingId, Customer $customer, string $date, float $qty, float $subTotal): void
    {
        $sale = Sale::create([
            'date' => $date,
            'reporting_date' => $date,
            'reference' => 'SL-' . uniqid(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'setting_id' => $settingId,
            'status' => 'DISPATCHED',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'paid_amount' => $subTotal,
            'total_amount' => $subTotal,
            'due_amount' => 0,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'shipping_amount' => 0,
            'is_tax_included' => false,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => $qty,
            'price' => $subTotal / $qty,
            'unit_price' => $subTotal / $qty,
            'sub_total' => $subTotal,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 10000,
            'cost_total_snapshot' => 10000 * $qty,
        ]);
    }

    public function test_user_assigned_to_a_and_b_excludes_c_from_hierarchy_global_stock_and_sales()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('stockInsights.access');
        $user->settings()->attach($this->settingA->id, ['role_id' => $this->adminRole->id]);
        $user->settings()->attach($this->settingB->id, ['role_id' => $this->adminRole->id]);

        $testable = Livewire::actingAs($user)->test(StockInsights::class);

        // Check hierarchy
        $hierarchy = $testable->viewData('hierarchy');
        $settingIdsInHierarchy = $hierarchy->pluck('setting_id')->all();
        $this->assertEqualsCanonicalizing([$this->settingA->id, $this->settingB->id], $settingIdsInHierarchy);
        $this->assertNotContains($this->settingC->id, $settingIdsInHierarchy);

        // Check rows
        $rows = $testable->viewData('rows');
        $this->assertCount(1, $rows);
        $row = $rows->first();

        // Stok Global should be A (10) + B (20) = 30 (excluding C = 50)
        $this->assertEquals(30.0, $row->globalStock->totalGood);

        // Business columns should only contain A and B
        $this->assertArrayHasKey($this->settingA->id, $row->businesses);
        $this->assertArrayHasKey($this->settingB->id, $row->businesses);
        $this->assertArrayNotHasKey($this->settingC->id, $row->businesses);

        // Sales measures: sold quantity = A (1) + B (2) = 3 (excluding C = 5)
        $this->assertEquals(3.0, $row->soldQuantity);
        $this->assertEquals(300000.0, $row->salesValue);
    }

    public function test_super_admin_includes_all_businesses_in_pantauan_stok()
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('Super Admin');
        $superAdmin->givePermissionTo('stockInsights.access');

        $testable = Livewire::actingAs($superAdmin)->test(StockInsights::class);

        // Check hierarchy includes all
        $hierarchy = $testable->viewData('hierarchy');
        $settingIdsInHierarchy = $hierarchy->pluck('setting_id')->all();
        $this->assertEqualsCanonicalizing([$this->settingA->id, $this->settingB->id, $this->settingC->id], $settingIdsInHierarchy);

        // Check rows
        $rows = $testable->viewData('rows');
        $row = $rows->first();

        // Stok Global should include A (10) + B (20) + C (50) = 80
        $this->assertEquals(80.0, $row->globalStock->totalGood);

        // Business columns should contain A, B, and C
        $this->assertArrayHasKey($this->settingA->id, $row->businesses);
        $this->assertArrayHasKey($this->settingB->id, $row->businesses);
        $this->assertArrayHasKey($this->settingC->id, $row->businesses);

        // Sales measures: sold quantity = A (1) + B (2) + C (5) = 8
        $this->assertEquals(8.0, $row->soldQuantity);
        $this->assertEquals(800000.0, $row->salesValue);
    }

    public function test_product_owned_by_b_stocked_at_a_is_counted_for_user_assigned_only_to_a(): void
    {
        $userOnlyA = User::factory()->create();
        $userOnlyA->givePermissionTo('stockInsights.access');
        $userOnlyA->settings()->attach($this->settingA->id, ['role_id' => $this->adminRole->id]);

        $productOwnedByB = Product::forceCreate([
            'setting_id' => $this->settingB->id,
            'product_name' => 'Produk Milik Bisnis B',
            'product_code' => 'OWNED-B-001',
            'product_cost' => 15000,
            'product_price' => 30000,
            'product_stock_alert' => 5,
            'stock_managed' => true,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $productOwnedByB->id,
            'location_id' => $this->locationA->id,
            'quantity' => 15,
            'quantity_tax' => 15,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $testable = Livewire::actingAs($userOnlyA)->test(StockInsights::class);

        $rows = $testable->viewData('rows');
        $rowB = collect($rows->items())->firstWhere('productId', $productOwnedByB->id);

        $this->assertNotNull($rowB);
        $this->assertEquals(15.0, $rowB->globalStock->totalGood);
        $this->assertEquals(15.0, $rowB->businesses[$this->settingA->id]['stock']->totalGood);
        $this->assertArrayNotHasKey($this->settingB->id, $rowB->businesses);
    }
}
