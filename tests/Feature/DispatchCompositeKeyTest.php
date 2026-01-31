<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DispatchCompositeKeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function createSetting(): Setting
    {
        return Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'company@example.com',
            'company_phone' => '1234567890',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => '123 Testing Lane',
        ]);
    }

    private function createProduct(Setting $setting, $name = 'Test Product', $code = 'TST-001'): Product
    {
        return Product::create([
            'product_name' => $name,
            'product_code' => $code,
            'setting_id' => $setting->id,
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 1500,
            'category_id' => 1,
            'product_unit' => 1,
        ]);
    }

    public function test_dispatch_aggregation_uses_composite_key(): void
    {
        $setting = $this->createSetting();
        $paymentTerm = PaymentTerm::create(['name' => 'Net 30', 'longevity' => 30]);
        $customer = Customer::factory()->create(['setting_id' => $setting->id, 'payment_term_id' => $paymentTerm->id]);
        
        Permission::firstOrCreate(['name' => 'sales.dispatch']);
        $user = User::factory()->create();
        $user->givePermissionTo('sales.dispatch');
        $this->actingAs($user);

        // Create dependencies for product
        $category = \Modules\Product\Entities\Category::create([
            'category_name' => 'Category', 
            'category_code' => 'CAT', 
            'setting_id' => $setting->id,
            'created_by' => $user->id
        ]);
        $unit = \Modules\Setting\Entities\Unit::create([
            'name' => 'Unit', 
            'short_name' => 'U', 
            'operator' => '*', 
            'operation_value' => 1, 
            'setting_id' => $setting->id,
        ]);

        $product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TST-001',
            'setting_id' => $setting->id,
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 1500,
            'category_id' => $category->id,
            'product_unit' => $unit->id,
        ]);

        // Create a sale
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 3000,
            'paid_amount' => 0,
            'due_amount' => 3000,
            'status' => 'Approved',
            'payment_status' => 'Unpaid',
            'payment_method' => 'cash',
            'payment_term_id' => $paymentTerm->id,
            'setting_id' => $setting->id,
            'is_tax_included' => false,
        ]);

        // Detail 1: Standalone product
        $detail1 = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1500,
            'unit_price' => 1500,
            'sub_total' => 1500,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        // Detail 2: Another detail that will have a bundle item
        $parentProduct = Product::create([
            'product_name' => 'Parent Product',
            'product_code' => 'PRNT-001',
            'setting_id' => $setting->id,
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 1500,
            'category_id' => $category->id,
            'product_unit' => $unit->id,
        ]);

        $detail2 = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $parentProduct->id, // Use real product ID
            'product_name' => 'Parent Product',
            'product_code' => 'PRNT-001',
            'quantity' => 1,
            'price' => 1500,
            'unit_price' => 1500,
            'sub_total' => 1500,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        // Bundle item for detail 2: The same Product A
        SaleBundleItem::create([
            'sale_detail_id' => $detail2->id,
            'sale_id' => $sale->id,
            'bundle_id' => 10, // Some bundle ID
            'bundle_item_id' => 101,
            'product_id' => $product->id,
            'name' => $product->product_name,
            'price' => 0,
            'quantity' => 1,
            'sub_total' => 0,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('sales.dispatch', $sale));

        $response->assertStatus(200);
        
        $aggregatedProducts = $response->viewData('aggregatedProducts');
        
        // Assert that we have two distinct keys for the same product_id
        // Key 1: product_id - tax_id - 0
        // Key 2: product_id - tax_id - bundle_id
        
        $standaloneKey = $product->id . '--0'; // null-tax is empty string or null in string concatenation?
        // Actually the code does: $key = $pid . '-' . $taxId . '-' . $bundleId;
        // So with null taxId: $product->id . '--0'
        
        $bundleKey = $product->id . '--10';
        
        $this->assertArrayHasKey($standaloneKey, $aggregatedProducts, "Standalone product key missing");
        $this->assertArrayHasKey($bundleKey, $aggregatedProducts, "Bundle product key missing");
        
        $this->assertEquals(1, $aggregatedProducts[$standaloneKey]['total_quantity']);
        $this->assertEquals(1, $aggregatedProducts[$bundleKey]['total_quantity']);
    }
}
