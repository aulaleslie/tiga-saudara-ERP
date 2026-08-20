<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Location;
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

    public function test_same_component_in_two_distinct_bundles_remains_separate_demand(): void
    {
        $setting = $this->createSetting();
        $paymentTerm = PaymentTerm::create(['name' => 'Net 30', 'longevity' => 30]);
        $customer = Customer::factory()->create(['setting_id' => $setting->id, 'payment_term_id' => $paymentTerm->id]);

        Permission::firstOrCreate(['name' => 'sales.dispatch']);
        $user = User::factory()->create();
        $user->givePermissionTo('sales.dispatch');

        $category = \Modules\Product\Entities\Category::create([
            'category_name' => 'Category',
            'category_code' => 'CAT',
            'setting_id' => $setting->id,
            'created_by' => $user->id,
        ]);
        $unit = \Modules\Setting\Entities\Unit::create([
            'name' => 'Unit',
            'short_name' => 'U',
            'operator' => '*',
            'operation_value' => 1,
            'setting_id' => $setting->id,
        ]);

        $component = $this->createProduct($setting, 'Shared Component', 'SHARED-001');

        $bundleParentOne = Product::create([
            'product_name' => 'Bundle One',
            'product_code' => 'BUNDLE-ONE',
            'setting_id' => $setting->id,
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 1500,
            'category_id' => $category->id,
            'product_unit' => $unit->id,
        ]);

        $bundleParentTwo = Product::create([
            'product_name' => 'Bundle Two',
            'product_code' => 'BUNDLE-TWO',
            'setting_id' => $setting->id,
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 1500,
            'category_id' => $category->id,
            'product_unit' => $unit->id,
        ]);

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
            'reference' => 'SO-TWO-BUNDLES',
        ]);

        $detailOne = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $bundleParentOne->id,
            'product_name' => $bundleParentOne->product_name,
            'product_code' => $bundleParentOne->product_code,
            'quantity' => 1,
            'price' => 1500,
            'unit_price' => 1500,
            'sub_total' => 1500,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        $detailTwo = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $bundleParentTwo->id,
            'product_name' => $bundleParentTwo->product_name,
            'product_code' => $bundleParentTwo->product_code,
            'quantity' => 1,
            'price' => 1500,
            'unit_price' => 1500,
            'sub_total' => 1500,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $detailOne->id,
            'product_id' => $component->id,
            'bundle_id' => 20,
            'bundle_item_id' => 1,
            'name' => $component->product_name,
            'price' => 0,
            'quantity' => 2,
            'sub_total' => 0,
        ]);

        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $detailTwo->id,
            'product_id' => $component->id,
            'bundle_id' => 30,
            'bundle_item_id' => 1,
            'name' => $component->product_name,
            'price' => 0,
            'quantity' => 3,
            'sub_total' => 0,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('sales.dispatch', $sale));

        $response->assertStatus(200);
        $aggregatedProducts = $response->viewData('aggregatedProducts');

        $bundleOneKey = $component->id . '--20';
        $bundleTwoKey = $component->id . '--30';

        $this->assertArrayHasKey($bundleOneKey, $aggregatedProducts, 'Component demand under bundle 20 missing');
        $this->assertArrayHasKey($bundleTwoKey, $aggregatedProducts, 'Component demand under bundle 30 missing');
        $this->assertEquals(2, $aggregatedProducts[$bundleOneKey]['total_quantity']);
        $this->assertEquals(3, $aggregatedProducts[$bundleTwoKey]['total_quantity']);
    }

    public function test_same_product_under_different_tax_buckets_remains_separate_demand(): void
    {
        $setting = $this->createSetting();
        $paymentTerm = PaymentTerm::create(['name' => 'Net 30', 'longevity' => 30]);
        $customer = Customer::factory()->create(['setting_id' => $setting->id, 'payment_term_id' => $paymentTerm->id]);

        Permission::firstOrCreate(['name' => 'sales.dispatch']);
        $user = User::factory()->create();
        $user->givePermissionTo('sales.dispatch');

        $category = \Modules\Product\Entities\Category::create([
            'category_name' => 'Category',
            'category_code' => 'CAT',
            'setting_id' => $setting->id,
            'created_by' => $user->id,
        ]);
        $unit = \Modules\Setting\Entities\Unit::create([
            'name' => 'Unit',
            'short_name' => 'U',
            'operator' => '*',
            'operation_value' => 1,
            'setting_id' => $setting->id,
        ]);

        $tax = \Modules\Setting\Entities\Tax::create([
            'name' => 'PPN',
            'value' => 11,
        ]);

        $product = $this->createProduct($setting, 'Taxed Both Ways', 'TAX-DUAL-001');

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
            'total_amount' => 4500,
            'paid_amount' => 0,
            'due_amount' => 4500,
            'status' => 'Approved',
            'payment_status' => 'Unpaid',
            'payment_method' => 'cash',
            'payment_term_id' => $paymentTerm->id,
            'setting_id' => $setting->id,
            'is_tax_included' => false,
            'reference' => 'SO-TAX-SPLIT',
        ]);

        // Same product, no-tax line: quantity 2.
        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'price' => 1500,
            'unit_price' => 1500,
            'sub_total' => 3000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        // Same product, taxed line: quantity 3.
        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 3,
            'price' => 500,
            'unit_price' => 500,
            'sub_total' => 1500,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 165,
            'tax_id' => $tax->id,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('sales.dispatch', $sale));

        $response->assertStatus(200);
        $aggregatedProducts = $response->viewData('aggregatedProducts');

        $noTaxKey = $product->id . '-' . null . '-0';
        $taxedKey = $product->id . '-' . $tax->id . '-0';

        $this->assertArrayHasKey($noTaxKey, $aggregatedProducts, 'No-tax demand missing');
        $this->assertArrayHasKey($taxedKey, $aggregatedProducts, 'Taxed demand missing');
        $this->assertEquals(2, $aggregatedProducts[$noTaxKey]['total_quantity']);
        $this->assertEquals(3, $aggregatedProducts[$taxedKey]['total_quantity']);
    }

    public function test_repeated_equivalent_bundle_rows_aggregate_demand_and_dispatch_exactly_once(): void
    {
        $setting = $this->createSetting();
        $paymentTerm = PaymentTerm::create(['name' => 'Net 30', 'longevity' => 30]);
        $customer = Customer::factory()->create(['setting_id' => $setting->id, 'payment_term_id' => $paymentTerm->id]);

        Permission::firstOrCreate(['name' => 'sales.dispatch']);
        Permission::firstOrCreate(['name' => 'salesDispatches.approval']);
        $user = User::factory()->create();
        $user->givePermissionTo(['sales.dispatch', 'salesDispatches.approval']);

        $category = \Modules\Product\Entities\Category::create([
            'category_name' => 'Category',
            'category_code' => 'CAT',
            'setting_id' => $setting->id,
            'created_by' => $user->id,
        ]);
        $unit = \Modules\Setting\Entities\Unit::create([
            'name' => 'Unit',
            'short_name' => 'U',
            'operator' => '*',
            'operation_value' => 1,
            'setting_id' => $setting->id,
        ]);

        $location = Location::create(['name' => 'Warehouse 1', 'setting_id' => $setting->id]);

        $component = Product::create([
            'product_name' => 'Component',
            'product_code' => 'COMP-REP',
            'setting_id' => $setting->id,
            'product_quantity' => 20,
            'product_cost' => 100,
            'stock_managed' => true,
            'product_price' => 150,
            'category_id' => $category->id,
            'product_unit' => $unit->id,
        ]);

        ProductStock::create([
            'product_id' => $component->id,
            'location_id' => $location->id,
            'quantity' => 20,
            'quantity_tax' => 0,
            'quantity_non_tax' => 20,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $parentProduct = Product::create([
            'product_name' => 'Bundle Parent',
            'product_code' => 'BP-REP',
            'setting_id' => $setting->id,
            'product_quantity' => 10,
            'product_cost' => 1000,
            'stock_managed' => true,
            'product_price' => 1500,
            'category_id' => $category->id,
            'product_unit' => $unit->id,
        ]);

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
            'reference' => 'SO-REPEAT-BUNDLE',
        ]);

        // Same bundle definition (bundle_id = 5) persisted as two separate
        // transaction rows: two distinct sale_details, each contributing one
        // component unit under the same bundle_id.
        foreach ([1, 2] as $i) {
            $parentDetail = SaleDetails::create([
                'sale_id' => $sale->id,
                'product_id' => $parentProduct->id,
                'product_name' => $parentProduct->product_name,
                'product_code' => $parentProduct->product_code,
                'quantity' => 1,
                'price' => 1500,
                'unit_price' => 1500,
                'sub_total' => 1500,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax_amount' => 0,
                'tax_id' => null,
            ]);

            SaleBundleItem::create([
                'sale_id' => $sale->id,
                'sale_detail_id' => $parentDetail->id,
                'product_id' => $component->id,
                'bundle_id' => 5,
                'bundle_item_id' => $i,
                'name' => $component->product_name,
                'price' => 0,
                'quantity' => 1,
                'sub_total' => 0,
            ]);
        }

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('sales.dispatch', $sale));
        $response->assertStatus(200);

        $aggregated = $response->viewData('aggregatedProducts');
        $componentKey = $component->id . '--5';
        $this->assertArrayHasKey($componentKey, $aggregated);
        $this->assertEquals(2, $aggregated[$componentKey]['total_quantity'], 'Equivalent bundle rows must aggregate demand under one fulfillment key');

        // Approve the full aggregated demand (2) in a single dispatch.
        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('sales.storeDispatch', $sale), [
                'dispatch_date' => now()->toDateString(),
                'dispatchedQuantities' => [$componentKey => 2],
                'selectedLocations' => [$componentKey => $location->id],
                'acknowledge_lifecycle_warning' => true,
            ]);

        $response->assertRedirect(route('sales.dispatches.index'));
        $response->assertSessionHasNoErrors();

        $dispatch = Dispatch::where('sale_id', $sale->id)->first();
        $this->assertNotNull($dispatch);
        $details = DispatchDetail::where('dispatch_id', $dispatch->id)->get();
        $this->assertEquals(1, $details->count(), 'Aggregated demand should create exactly one dispatch detail row');
        $this->assertEquals(2, $details->first()->dispatched_quantity);

        $approveResponse = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('dispatches.approve', $dispatch), [
                'acknowledge_lifecycle_warning' => true,
            ]);
        $approveResponse->assertRedirect();
        $dispatch->refresh();
        $this->assertEquals(Dispatch::STATUS_APPROVED, $dispatch->status);

        $component->refresh();
        $this->assertEquals(18, $component->product_quantity, 'Inventory effect should apply exactly once for the aggregated quantity of 2');

        $transactionCount = \Modules\Product\Entities\Transaction::where('product_id', $component->id)->count();
        $this->assertEquals(1, $transactionCount, 'Exactly one inventory transaction should record the aggregated approved quantity');

        // Aggregated demand is now fully satisfied; no further quantity should be dispatchable.
        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('sales.storeDispatch', $sale), [
                'dispatch_date' => now()->toDateString(),
                'dispatchedQuantities' => [$componentKey => 1],
                'selectedLocations' => [$componentKey => $location->id],
                'acknowledge_lifecycle_warning' => true,
            ]);

        $response->assertSessionHasErrors();
        $this->assertEquals(1, Dispatch::where('sale_id', $sale->id)->count(), 'No new dispatch should be created once aggregate demand is satisfied');
    }

    public function test_partial_dispatch_across_multiple_locations_never_exceeds_aggregate_demand(): void
    {
        $setting = $this->createSetting();
        $paymentTerm = PaymentTerm::create(['name' => 'Net 30', 'longevity' => 30]);
        $customer = Customer::factory()->create(['setting_id' => $setting->id, 'payment_term_id' => $paymentTerm->id]);

        Permission::firstOrCreate(['name' => 'sales.dispatch']);
        Permission::firstOrCreate(['name' => 'salesDispatches.approval']);
        $user = User::factory()->create();
        $user->givePermissionTo(['sales.dispatch', 'salesDispatches.approval']);

        $category = \Modules\Product\Entities\Category::create([
            'category_name' => 'Category',
            'category_code' => 'CAT',
            'setting_id' => $setting->id,
            'created_by' => $user->id,
        ]);
        $unit = \Modules\Setting\Entities\Unit::create([
            'name' => 'Unit',
            'short_name' => 'U',
            'operator' => '*',
            'operation_value' => 1,
            'setting_id' => $setting->id,
        ]);

        $locationA = Location::create(['name' => 'Warehouse A', 'setting_id' => $setting->id]);
        $locationB = Location::create(['name' => 'Warehouse B', 'setting_id' => $setting->id]);

        $product = Product::create([
            'product_name' => 'Multi Location Product',
            'product_code' => 'MLP-001',
            'setting_id' => $setting->id,
            'product_quantity' => 10,
            'product_cost' => 100,
            'stock_managed' => true,
            'product_price' => 150,
            'category_id' => $category->id,
            'product_unit' => $unit->id,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $locationA->id,
            'quantity' => 6,
            'quantity_tax' => 0,
            'quantity_non_tax' => 6,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $locationB->id,
            'quantity' => 4,
            'quantity_tax' => 0,
            'quantity_non_tax' => 4,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

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
            'total_amount' => 1500,
            'paid_amount' => 0,
            'due_amount' => 1500,
            'status' => 'Approved',
            'payment_status' => 'Unpaid',
            'payment_method' => 'cash',
            'payment_term_id' => $paymentTerm->id,
            'setting_id' => $setting->id,
            'is_tax_included' => false,
            'reference' => 'SO-MULTI-LOC',
        ]);

        // Total demand is 10.
        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 10,
            'price' => 150,
            'unit_price' => 150,
            'sub_total' => 1500,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        $key = $product->id . '--0';

        // First partial dispatch: 6 from Warehouse A. Under aggregate demand of 10.
        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('sales.storeDispatch', $sale), [
                'dispatch_date' => now()->toDateString(),
                'dispatchedQuantities' => [$key => 6],
                'selectedLocations' => [$key => $locationA->id],
            ]);
        $response->assertRedirect(route('sales.dispatches.index'));
        $response->assertSessionHasNoErrors();

        $dispatch1 = Dispatch::where('sale_id', $sale->id)->first();
        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('dispatches.approve', $dispatch1))
            ->assertRedirect();

        $dispatch1->refresh();
        $this->assertEquals(Dispatch::STATUS_APPROVED, $dispatch1->status);
        $this->assertEquals(6, DispatchDetail::where('dispatch_id', $dispatch1->id)->first()->dispatched_quantity);

        // Second dispatch attempting the remaining 4 from Warehouse B succeeds
        // (6 approved + 4 pending = 10, exactly the aggregate demand).
        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('sales.storeDispatch', $sale), [
                'dispatch_date' => now()->toDateString(),
                'dispatchedQuantities' => [$key => 4],
                'selectedLocations' => [$key => $locationB->id],
            ]);
        $response->assertRedirect(route('sales.dispatches.index'));
        $response->assertSessionHasNoErrors();

        $dispatch2 = Dispatch::where('sale_id', $sale->id)->where('id', '!=', $dispatch1->id)->first();
        $this->assertNotNull($dispatch2);
        $this->assertEquals(4, DispatchDetail::where('dispatch_id', $dispatch2->id)->first()->dispatched_quantity);

        // A further request for even 1 more unit must fail: pending (4) + approved (6) already equals demand (10).
        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('sales.storeDispatch', $sale), [
                'dispatch_date' => now()->toDateString(),
                'dispatchedQuantities' => [$key => 1],
                'selectedLocations' => [$key => $locationA->id],
            ]);
        $response->assertSessionHasErrors();

        // Approve the second (Warehouse B) dispatch: exactly one inventory effect per approved location quantity.
        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('dispatches.approve', $dispatch2))
            ->assertRedirect();

        $product->refresh();
        $this->assertEquals(0, $product->product_quantity, 'Total stock should be reduced by exactly the aggregate demand of 10');

        $stockA = ProductStock::where('product_id', $product->id)->where('location_id', $locationA->id)->first();
        $stockB = ProductStock::where('product_id', $product->id)->where('location_id', $locationB->id)->first();
        $this->assertEquals(0, $stockA->quantity_non_tax, 'Warehouse A location stock should reflect its own 6-unit approved dispatch');

        // Exactly one inventory transaction per approved location quantity, not duplicated or merged.
        $transactions = \Modules\Product\Entities\Transaction::where('product_id', $product->id)
            ->orderBy('location_id')
            ->get();
        $this->assertCount(2, $transactions, 'Expected exactly one inventory transaction per approved location dispatch');

        $transactionA = $transactions->firstWhere('location_id', $locationA->id);
        $transactionB = $transactions->firstWhere('location_id', $locationB->id);
        $this->assertNotNull($transactionA, 'Warehouse A should have its own inventory transaction');
        $this->assertNotNull($transactionB, 'Warehouse B should have its own inventory transaction');
        $this->assertEquals(-6, $transactionA->quantity, 'Warehouse A transaction should move exactly 6 units');
        $this->assertEquals(-4, $transactionB->quantity, 'Warehouse B transaction should move exactly 4 units');
        $this->assertEquals(0, $stockB->quantity_non_tax, 'Warehouse B location stock should reflect its own 4-unit approved dispatch');
    }
}
