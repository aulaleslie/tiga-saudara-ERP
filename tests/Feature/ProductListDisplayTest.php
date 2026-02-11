<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\People\Entities\Supplier;
use Tests\TestCase;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class ProductListDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_list_shows_link_and_stock_pills()
    {
        // Setup
        $setting1 = Setting::create([
            'company_name' => 'Tenant One',
            'company_email' => 't1@example.com',
            'company_phone' => '123',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 't1@example.com',
            'footer_text' => 'T1 Footer',
            'company_address' => 'Addr 1',
        ]);

        $setting2 = Setting::create([
            'company_name' => 'Tenant Two',
            'company_email' => 't2@example.com',
            'company_phone' => '456',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 't2@example.com',
            'footer_text' => 'T2 Footer',
            'company_address' => 'Addr 2',
        ]);

        $location1 = Location::create([
            'name' => 'Loc 1',
            'setting_id' => $setting1->id,
        ]);
        
        $location2 = Location::create([
            'name' => 'Loc 2',
            'setting_id' => $setting2->id,
        ]);

        $product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'product_quantity' => 20,
            'setting_id' => $setting1->id,
            'product_cost' => 0,
            'product_price' => 0,
            'product_stock_alert' => 0,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
            'profit_percentage' => 0,
            'purchase_price' => 0,
            'sale_price' => 0,
            'stock_managed' => true,
        ]);

        // Stock for Tenant 1
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location1->id,
            'quantity' => 12,
            'broken_quantity' => 2,
            'quantity_non_tax' => 0,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        // Stock for Tenant 2
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location2->id,
            'quantity' => 10,
            'broken_quantity' => 0,
            'quantity_non_tax' => 0,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        // Create Role first
        $role = Role::create(['name' => 'admin']);
        $permission = Permission::firstOrCreate(['name' => 'products.access']);
        $role->givePermissionTo($permission);

        $user = User::factory()->create(['is_active' => 1]);
        $user->settings()->attach($setting1->id, ['role_id' => $role->id]);
        
        // Create Tenant 3 with no stock
        $setting3 = Setting::create([
            'company_name' => 'Tenant Three',
            'company_email' => 't3@example.com',
            'company_phone' => '789',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 't3@example.com',
            'footer_text' => 'T3 Footer',
            'company_address' => 'Addr 3',
        ]);
        
        $location3 = Location::create([
            'name' => 'Loc 3',
            'setting_id' => $setting3->id,
        ]);
        
        // Attach user to setting 3
        $user->settings()->attach($setting3->id, ['role_id' => $role->id]);

        $user->assignRole($role); 
        // Force refresh user permissions
        $user->refresh();

        $this->actingAs($user);

        // Act
        // Verify for Tenant 3 (0 stock)
        $response = $this->withSession(['setting_id' => $setting3->id])
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('products.index', ['draw' => 1]));

        // Assert
        $response->assertStatus(200);
        $data = $response->json('data');
        
        $this->assertNotEmpty($data);
        $row = collect($data)->firstWhere('id', $product->id);
        $this->assertNotNull($row);

        $productCodeHtml = $row['product_code'];
        
        // Verify Breakdown for Active Tenant (Tenant 3 has 0 stock)
        $this->assertStringContainsString('0', $row['total_stock']);
        $this->assertStringContainsString('0', $row['good_stock']);
        $this->assertStringContainsString('0', $row['broken_stock']);

        // Verify other tenants are NOT shown
        $this->assertStringNotContainsString('TENANT ONE', $productCodeHtml);
        $this->assertStringNotContainsString('TENANT TWO', $productCodeHtml);
        
        // --- Switch to Tenant 1 and Verify ---
        
        // Simulate switching session or user context
        // Ideally we start a new request or modify the session, but actingAs with user who "defaults" to a setting works if session is clear
        // But here we rely on the controller logic: session('setting_id') ?? user->settings()->first()
        
        // Let's create a new request for Tenant 1
        $this->withSession(['setting_id' => $setting1->id]);
        
        $response = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('products.index', ['draw' => 1]));
            
        $data = $response->json('data');
        $row = collect($data)->firstWhere('id', $product->id);
        $productCodeHtml = $row['product_code'];

        // Tenant 1 has 12 Total, 2 Broken, 10 Good
        $this->assertStringContainsString('12', $row['total_stock']);
        $this->assertStringContainsString('10', $row['good_stock']);
        $this->assertStringContainsString('2', $row['broken_stock']);
    }

    public function test_non_stock_managed_product_shows_dash_in_all_columns()
    {
        // Setup
        $setting = Setting::first() ?? Setting::create([
            'company_name' => 'Tenant One',
            'company_email' => 't1@example.com',
            'company_phone' => '123',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 't1@example.com',
            'footer_text' => 'T1 Footer',
            'company_address' => 'Addr 1',
        ]);

        $product = Product::create([
            'product_name' => 'Non Stock Product',
            'product_code' => 'NS-001',
            'product_quantity' => 0,
            'setting_id' => $setting->id,
            'product_cost' => 0,
            'product_price' => 0,
            'product_stock_alert' => 0,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
            'profit_percentage' => 0,
            'purchase_price' => 0,
            'sale_price' => 0,
            'stock_managed' => false,
        ]);

        $user = User::first() ?? User::factory()->create(['is_active' => 1]);
        $role = Role::firstOrCreate(['name' => 'admin']);
        $permission = Permission::firstOrCreate(['name' => 'products.access']);
        $role->givePermissionTo($permission);
        
        if (!$user->hasRole('admin')) {
            $user->assignRole($role);
        }
        
        if (!$user->settings()->where('settings.id', $setting->id)->exists()) {
            $user->settings()->attach($setting->id, ['role_id' => $role->id]);
        }

        $this->actingAs($user);

        // Act
        $response = $this->withSession(['setting_id' => $setting->id])
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('products.index', ['draw' => 1]));

        // Assert
        $response->assertStatus(200);
        $data = $response->json('data');
        $row = collect($data)->firstWhere('id', $product->id);
        $this->assertNotNull($row);

        // All 5 quantity columns should show '-'
        $this->assertEquals('-', $row['total_stock']);
        $this->assertEquals('-', $row['good_stock']);
        $this->assertEquals('-', $row['broken_stock']);
        $this->assertEquals('-', $row['on_order_stock']);
        $this->assertEquals('-', $row['in_return_process_stock']);
    }

    public function test_total_stock_column_includes_on_order_and_in_return_process_quantities()
    {
        $setting = Setting::create([
            'company_name' => 'Tenant One',
            'company_email' => 't1@example.com',
            'company_phone' => '123',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 't1@example.com',
            'footer_text' => 'T1 Footer',
            'company_address' => 'Addr 1',
        ]);

        $location = Location::create([
            'name' => 'Loc 1',
            'setting_id' => $setting->id,
        ]);

        $supplier = Supplier::create([
            'supplier_name' => 'Supplier One',
            'supplier_email' => 'supplier1@example.com',
            'supplier_phone' => '12345',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $setting->id,
        ]);

        $product = Product::create([
            'product_name' => 'Projected Product',
            'product_code' => 'PRJ-001',
            'product_quantity' => 10,
            'setting_id' => $setting->id,
            'product_cost' => 0,
            'product_price' => 0,
            'product_stock_alert' => 0,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
            'profit_percentage' => 0,
            'purchase_price' => 0,
            'sale_price' => 0,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 10,
            'broken_quantity' => 0,
            'quantity_non_tax' => 0,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(7),
            'reference' => 'PO-ONORDER-001',
            'supplier_id' => $supplier->id,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'total_amount' => 3000,
            'paid_amount' => 0,
            'due_amount' => 3000,
            'setting_id' => $setting->id,
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 3,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 3000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PR-INRETURN-001',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'approval_status' => 'APPROVED',
            'return_dispatch_status' => 'DISPATCHED',
            'status' => 'DATA VERIFIED',
            'setting_id' => $setting->id,
            'location_id' => $location->id,
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'payment_status' => 'UNPAID',
            'payment_method' => 'CASH',
            'tax_amount' => 0,
            'tax_percentage' => 0,
            'discount_amount' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
        ]);

        PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 2000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $location->id,
        ]);

        $role = Role::firstOrCreate(['name' => 'admin']);
        $permission = Permission::firstOrCreate(['name' => 'products.access']);
        $role->givePermissionTo($permission);

        $user = User::factory()->create(['is_active' => 1]);
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        $this->actingAs($user);

        $response = $this->withSession(['setting_id' => $setting->id])
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('products.index', ['draw' => 1]));

        $response->assertStatus(200);
        $row = collect($response->json('data'))->firstWhere('id', $product->id);
        $this->assertNotNull($row);

        // total(10) + on_order(3) + in_return_process(2) = 15
        $this->assertStringContainsString('15', $row['total_stock']);
        $this->assertStringContainsString('3', $row['on_order_stock']);
        $this->assertStringContainsString('2', $row['in_return_process_stock']);
    }
}
