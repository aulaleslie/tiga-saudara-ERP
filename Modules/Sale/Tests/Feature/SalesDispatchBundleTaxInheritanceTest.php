<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SalesDispatchBundleTaxInheritanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function createSetup()
    {
        $setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'company@example.com',
            'company_phone' => '1234567890',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => '123 Testing Lane',
            'is_pkp' => true,
        ]);

        $paymentTerm = PaymentTerm::create(['name' => 'Net 30', 'longevity' => 30]);
        $customer = Customer::factory()->create(['setting_id' => $setting->id, 'payment_term_id' => $paymentTerm->id]);
        
        Permission::firstOrCreate(['name' => 'sales.access']);
        Permission::firstOrCreate(['name' => 'sales.dispatch']);
        Permission::firstOrCreate(['name' => 'salesDispatches.approval']);
        
        $user = User::factory()->create();
        $user->givePermissionTo(['sales.access', 'sales.dispatch', 'salesDispatches.approval']);

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

        $location = Location::create([
            'name' => 'Warehouse 1',
            'setting_id' => $setting->id,
        ]);

        $tax = Tax::create(['name' => 'PPN 11%', 'value' => 11]);

        return [$setting, $user, $customer, $location, $tax, $paymentTerm, $category, $unit];
    }

    public function test_bundle_component_inherits_tax_context_from_parent_detail(): void
    {
        [$setting, $user, $customer, $location, $tax, $paymentTerm, $category, $unit] = $this->createSetup();

        $bundleParent = Product::create([
            'product_name' => 'Bundle Parent',
            'product_code' => 'BP-001',
            'setting_id' => $setting->id,
            'product_quantity' => 1,
            'product_cost' => 1000,
            'product_price' => 2000,
            'category_id' => $category->id,
            'product_unit' => $unit->id,
            'stock_managed' => true,
        ]);

        $component = Product::create([
            'product_name' => 'Component',
            'product_code' => 'COMP-001',
            'setting_id' => $setting->id,
            'product_quantity' => 10,
            'product_cost' => 100,
            'product_price' => 0,
            'category_id' => $category->id,
            'product_unit' => $unit->id,
            'stock_managed' => true,
        ]);

        // Stock in TAXED pool for component
        ProductStock::create([
            'product_id' => $component->id,
            'location_id' => $location->id,
            'quantity' => 10,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Create Sale with TAXED parent
        $sale = Sale::create([
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'tax_amount' => 220,
            'tax_percentage' => 11,
            'discount_amount' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'cash',
            'payment_term_id' => $paymentTerm->id,
            'setting_id' => $setting->id,
            'is_tax_included' => false,
            'reference' => 'SO-BUNDLE-TAXED',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
        ]);

        $detail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $bundleParent->id,
            'product_name' => $bundleParent->product_name,
            'product_code' => $bundleParent->product_code,
            'quantity' => 1,
            'price' => 2000,
            'unit_price' => 2000,
            'sub_total' => 2000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 220,
            'tax_id' => $tax->id,
        ]);

        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $detail->id,
            'product_id' => $component->id,
            'bundle_id' => 99, // Arbitrary bundle ID
            'bundle_item_id' => 1,
            'name' => $component->product_name,
            'price' => 0,
            'quantity' => 1,
            'sub_total' => 0,
        ]);

        // 1. Verify dispatch page aggregation
        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('sales.dispatch', $sale))
            ->assertStatus(200)
            ->assertSee($component->id . '-' . $tax->id . '-99'); // Ensure composite key uses parent tax ID

        // 2. Verify storeDispatch validation
        $compositeKey = $component->id . '-' . $tax->id . '-99';
        $payload = [
            'dispatch_date' => now()->toDateString(),
            'dispatchedQuantities' => [$compositeKey => 1],
            'selectedLocations' => [$compositeKey => $location->id],
        ];

        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('sales.storeDispatch', $sale), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('dispatch_details', [
            'sale_id' => $sale->id,
            'product_id' => $component->id,
            'tax_id' => $tax->id,
            'bundle_id' => 99,
        ]);
    }

    public function test_bundle_component_uses_non_tax_bucket_if_parent_is_non_tax(): void
    {
        [$setting, $user, $customer, $location, $tax, $paymentTerm, $category, $unit] = $this->createSetup();

        $bundleParent = Product::create([
            'product_name' => 'Bundle Parent 2',
            'product_code' => 'BP-002',
            'setting_id' => $setting->id,
            'product_quantity' => 1,
            'product_cost' => 1000,
            'product_price' => 2000,
            'category_id' => $category->id,
            'product_unit' => $unit->id,
            'stock_managed' => true,
        ]);

        $component = Product::create([
            'product_name' => 'Non-Tax Component',
            'product_code' => 'COMP-NT',
            'setting_id' => $setting->id,
            'product_quantity' => 10,
            'product_cost' => 100,
            'product_price' => 0,
            'stock_managed' => true,
            'category_id' => $category->id,
            'product_unit' => $unit->id,
        ]);

        // Stock in NON-TAX pool only
        ProductStock::create([
            'product_id' => $component->id,
            'location_id' => $location->id,
            'quantity' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Create Sale with NON-TAX parent
        $sale = Sale::create([
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'tax_amount' => 0,
            'tax_percentage' => 0,
            'discount_amount' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'cash',
            'payment_term_id' => $paymentTerm->id,
            'setting_id' => $setting->id,
            'is_tax_included' => false,
            'reference' => 'SO-BUNDLE-NON-TAX',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
        ]);

        $detail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $bundleParent->id,
            'product_name' => $bundleParent->product_name,
            'product_code' => $bundleParent->product_code,
            'quantity' => 1,
            'price' => 2000,
            'unit_price' => 2000,
            'sub_total' => 2000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null, // NON-TAX
        ]);

        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $detail->id,
            'product_id' => $component->id,
            'bundle_id' => 88,
            'bundle_item_id' => 2,
            'name' => $component->product_name,
            'price' => 0,
            'quantity' => 1,
            'sub_total' => 0,
        ]);

        $compositeKey = $component->id . '--88'; // Key for non-tax
        $payload = [
            'dispatch_date' => now()->toDateString(),
            'dispatchedQuantities' => [$compositeKey => 1],
            'selectedLocations' => [$compositeKey => $location->id],
        ];

        // Should SUCCEED because it evaluates quantity_non_tax
        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('sales.storeDispatch', $sale), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }
}

