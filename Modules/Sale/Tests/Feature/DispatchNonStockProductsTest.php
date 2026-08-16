<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SaleBundleItem;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DispatchNonStockProductsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function createSetup($nonStockOnly = false, $mixedProducts = false)
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
        ]);

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

        // Non-stock product (service)
        $serviceProduct = Product::create([
            'product_name' => 'Repair Service',
            'product_code' => 'SVC-001',
            'setting_id' => $setting->id,
            'product_quantity' => 0,
            'product_cost' => 0,
            'stock_managed' => false,
            'product_price' => 500000,
            'category_id' => $category->id,
            'product_unit' => $unit->id,
        ]);

        // Stock-managed product
        $stockProduct = null;
        if ($mixedProducts) {
            $stockProduct = Product::create([
                'product_name' => 'Laptop',
                'product_code' => 'LPT-001',
                'setting_id' => $setting->id,
                'product_quantity' => 100,
                'product_cost' => 5000000,
                'stock_managed' => true,
                'product_price' => 7000000,
                'category_id' => $category->id,
                'product_unit' => $unit->id,
            ]);

            ProductStock::create([
                'product_id' => $stockProduct->id,
                'location_id' => $location->id,
                'quantity' => 100,
                'quantity_tax' => 0,
                'quantity_non_tax' => 100,
                'broken_quantity_tax' => 0,
                'broken_quantity_non_tax' => 0,
                'broken_quantity' => 0,
            ]);
        }

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
            'total_amount' => $mixedProducts ? 7500000 : 1000000,
            'paid_amount' => 0,
            'due_amount' => $mixedProducts ? 7500000 : 1000000,
            'status' => 'Approved',
            'payment_status' => 'Unpaid',
            'payment_method' => 'cash',
            'payment_term_id' => $paymentTerm->id,
            'setting_id' => $setting->id,
            'is_tax_included' => false,
            'reference' => 'SO-001'
        ]);

        // Add service line
        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $serviceProduct->id,
            'product_name' => $serviceProduct->product_name,
            'product_code' => $serviceProduct->product_code,
            'quantity' => 2,
            'price' => 500000,
            'unit_price' => 500000,
            'sub_total' => 1000000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        // Add stock product line if mixed
        if ($mixedProducts) {
            SaleDetails::create([
                'sale_id' => $sale->id,
                'product_id' => $stockProduct->id,
                'product_name' => $stockProduct->product_name,
                'product_code' => $stockProduct->product_code,
                'quantity' => 1,
                'price' => 7000000,
                'unit_price' => 7000000,
                'sub_total' => 7000000,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax_amount' => 0,
                'tax_id' => null,
            ]);
        }

        return [$setting, $user, $sale, $serviceProduct, $stockProduct, $location];
    }

    public function test_service_only_sale_partially_acknowledged(): void
    {
        [$setting, $user, $sale, $serviceProduct, $_, $location] = $this->createSetup(nonStockOnly: true);

        $compositeKey = $serviceProduct->id . '--0';
        $payload = [
            'dispatch_date' => now()->toDateString(),
            'dispatchedQuantities' => [$compositeKey => 1],
        ];

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('sales.storeDispatch', $sale), $payload);

        $response->assertRedirect();

        $dispatch = Dispatch::where('sale_id', $sale->id)->first();
        $this->assertNotNull($dispatch);
        $this->assertEquals(Dispatch::STATUS_PENDING, $dispatch->status);

        // Verify detail created without location
        $detail = DispatchDetail::where('dispatch_id', $dispatch->id)->first();
        $this->assertNotNull($detail);
        $this->assertNull($detail->location_id);
        $this->assertNull($detail->serial_numbers);
        $this->assertEquals(1, $detail->dispatched_quantity);
    }

    public function test_service_only_sale_partial_to_full_dispatch_status(): void
    {
        [$setting, $user, $sale, $serviceProduct, $_, $location] = $this->createSetup(nonStockOnly: true);

        $compositeKey = $serviceProduct->id . '--0';

        // Create first dispatch with partial quantity
        $dispatch1 = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now()->toDateString(),
            'status' => Dispatch::STATUS_PENDING,
        ]);

        DispatchDetail::create([
            'dispatch_id' => $dispatch1->id,
            'sale_id' => $sale->id,
            'product_id' => $serviceProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => null,
        ]);

        // Approve first dispatch
        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('dispatches.approve', $dispatch1));

        $sale->refresh();
        $this->assertEquals(Sale::STATUS_DISPATCHED_PARTIALLY, $sale->status);

        // Create second dispatch for remaining quantity
        $dispatch2 = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now()->toDateString(),
            'status' => Dispatch::STATUS_PENDING,
        ]);

        DispatchDetail::create([
            'dispatch_id' => $dispatch2->id,
            'sale_id' => $sale->id,
            'product_id' => $serviceProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => null,
        ]);

        // Approve second dispatch
        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('dispatches.approve', $dispatch2));

        $sale->refresh();
        $this->assertEquals(Sale::STATUS_DISPATCHED, $sale->status);
    }

    public function test_service_acknowledgement_has_no_inventory_side_effects(): void
    {
        [$setting, $user, $sale, $serviceProduct, $_, $location] = $this->createSetup(nonStockOnly: true);

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now()->toDateString(),
            'status' => Dispatch::STATUS_PENDING,
        ]);

        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $serviceProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => null,
        ]);

        $originalQty = $serviceProduct->product_quantity;

        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('dispatches.approve', $dispatch));

        $serviceProduct->refresh();
        $this->assertEquals($originalQty, $serviceProduct->product_quantity);
    }

    public function test_mixed_sale_service_and_stock_dispatch(): void
    {
        [$setting, $user, $sale, $serviceProduct, $stockProduct, $location] = $this->createSetup(mixedProducts: true);

        $serviceKey = $serviceProduct->id . '--0';
        $stockKey = $stockProduct->id . '--0';

        $payload = [
            'dispatch_date' => now()->toDateString(),
            'dispatchedQuantities' => [$serviceKey => 1, $stockKey => 1],
            'selectedLocations' => [$stockKey => $location->id],
        ];

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('sales.storeDispatch', $sale), $payload);

        $response->assertRedirect();

        $dispatch = Dispatch::where('sale_id', $sale->id)->first();
        $this->assertNotNull($dispatch);

        // Check both details exist
        $serviceDetail = DispatchDetail::where('dispatch_id', $dispatch->id)
            ->where('product_id', $serviceProduct->id)
            ->first();
        $this->assertNotNull($serviceDetail);
        $this->assertNull($serviceDetail->location_id);

        $stockDetail = DispatchDetail::where('dispatch_id', $dispatch->id)
            ->where('product_id', $stockProduct->id)
            ->first();
        $this->assertNotNull($stockDetail);
        $this->assertEquals($location->id, $stockDetail->location_id);
    }

    public function test_mixed_sale_stays_partial_until_service_acknowledged(): void
    {
        [$setting, $user, $sale, $serviceProduct, $stockProduct, $location] = $this->createSetup(mixedProducts: true);

        // Dispatch and approve stock product only
        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now()->toDateString(),
            'status' => Dispatch::STATUS_PENDING,
        ]);

        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $stockProduct->id,
            'dispatched_quantity' => 1,
            'location_id' => $location->id,
        ]);

        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('dispatches.approve', $dispatch));

        $sale->refresh();
        $this->assertEquals(Sale::STATUS_DISPATCHED_PARTIALLY, $sale->status);

        // Now dispatch and approve service
        $dispatch2 = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now()->toDateString(),
            'status' => Dispatch::STATUS_PENDING,
        ]);

        DispatchDetail::create([
            'dispatch_id' => $dispatch2->id,
            'sale_id' => $sale->id,
            'product_id' => $serviceProduct->id,
            'dispatched_quantity' => 2,
            'location_id' => null,
        ]);

        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('dispatches.approve', $dispatch2));

        $sale->refresh();
        $this->assertEquals(Sale::STATUS_DISPATCHED, $sale->status);
    }

    public function test_rejected_service_acknowledgement_does_not_count_toward_completion(): void
    {
        [$setting, $user, $sale, $serviceProduct, $_, $location] = $this->createSetup(nonStockOnly: true);

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now()->toDateString(),
            'status' => Dispatch::STATUS_PENDING,
        ]);

        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $serviceProduct->id,
            'dispatched_quantity' => 2,
            'location_id' => null,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('dispatches.reject', $dispatch), [
                'rejection_reason' => 'Service not needed'
            ]);

        $response->assertRedirect();

        $sale->refresh();
        $this->assertEquals(Sale::STATUS_APPROVED, $sale->status);
    }

    public function test_empty_dispatch_validation_fails(): void
    {
        [$setting, $user, $sale, $serviceProduct, $_, $location] = $this->createSetup(nonStockOnly: true);

        $payload = [
            'dispatch_date' => now()->toDateString(),
            'dispatchedQuantities' => [],
        ];

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('sales.storeDispatch', $sale), $payload);

        $response->assertSessionHasErrors('dispatchedQuantities');
        $this->assertNull(Dispatch::where('sale_id', $sale->id)->first());
    }

    public function test_zero_quantities_only_dispatch_fails(): void
    {
        [$setting, $user, $sale, $serviceProduct, $_, $location] = $this->createSetup(nonStockOnly: true);

        $compositeKey = $serviceProduct->id . '--0';
        $payload = [
            'dispatch_date' => now()->toDateString(),
            'dispatchedQuantities' => [$compositeKey => 0],
        ];

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('sales.storeDispatch', $sale), $payload);

        $response->assertSessionHasErrors('dispatchedQuantities');
        $this->assertNull(Dispatch::where('sale_id', $sale->id)->first());
    }

    public function test_non_stock_approval_no_inventory_side_effects(): void
    {
        [$setting, $user, $sale, $serviceProduct, $_, $location] = $this->createSetup(nonStockOnly: true);

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now()->toDateString(),
            'status' => Dispatch::STATUS_PENDING,
        ]);

        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $serviceProduct->id,
            'dispatched_quantity' => 2,
            'location_id' => null,
        ]);

        $originalProductQty = $serviceProduct->product_quantity;

        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('dispatches.approve', $dispatch));

        $dispatch->refresh();
        $this->assertEquals(Dispatch::STATUS_APPROVED, $dispatch->status);
        $this->assertNotNull($dispatch->approved_by);
        $this->assertNotNull($dispatch->approved_at);

        $serviceProduct->refresh();
        $this->assertEquals($originalProductQty, $serviceProduct->product_quantity, 'Product quantity should not change for non-stock product');

        // Verify no inventory transactions created for non-stock product
        $transactionCount = \Modules\Product\Entities\Transaction::where('product_id', $serviceProduct->id)->count();
        $this->assertEquals(0, $transactionCount, 'No inventory transactions should be created for non-stock products');

        // Verify no serial tracking for non-stock product
        $serialTrackingCount = \Modules\Sale\Entities\SalesOrderSerialTracking::where('sale_id', $sale->id)->count();
        $this->assertEquals(0, $serialTrackingCount, 'No serial tracking should be created for non-stock products');

        // Verify dispatch detail still has null location and serials
        $detail = DispatchDetail::where('dispatch_id', $dispatch->id)->first();
        $this->assertNull($detail->location_id);
        $this->assertNull($detail->serial_numbers);
        $this->assertEquals(2, $detail->dispatched_quantity);
    }

    public function test_mixed_service_and_stock_separate_line_items_dispatch(): void
    {
        // This tests a mixed sale where service and stock are separate line items (not a bundle)
        [$setting, $user, $sale, $serviceProduct, $stockProduct, $location] = $this->createSetup(mixedProducts: true);

        // Verify dispatch aggregation shows both service and stock items
        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('sales.dispatch', $sale));

        $response->assertSuccessful();

        // Dispatch and approve only stock (quantity 1)
        $stockKey = $stockProduct->id . '--0';
        $payload = [
            'dispatch_date' => now()->toDateString(),
            'dispatchedQuantities' => [$stockKey => 1],
            'selectedLocations' => [$stockKey => $location->id],
        ];

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('sales.storeDispatch', $sale), $payload);

        $response->assertRedirect();

        $dispatch1 = Dispatch::where('sale_id', $sale->id)->orderBy('id')->first();
        $this->assertNotNull($dispatch1);

        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('dispatches.approve', $dispatch1));

        $sale->refresh();
        $this->assertEquals(Sale::STATUS_DISPATCHED_PARTIALLY, $sale->status, 'Sale should be DISPATCHED_PARTIALLY after stock approval');

        $stockProduct->refresh();
        $this->assertEquals(99, $stockProduct->product_quantity, 'Stock quantity should decrease by 1');

        // Now dispatch and approve service (quantity 2)
        $serviceKey = $serviceProduct->id . '--0';
        $payload2 = [
            'dispatch_date' => now()->toDateString(),
            'dispatchedQuantities' => [$serviceKey => 2],
        ];

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('sales.storeDispatch', $sale), $payload2);

        $response->assertRedirect();

        $dispatch2 = Dispatch::where('sale_id', $sale->id)->orderBy('id', 'desc')->first();
        $this->assertNotNull($dispatch2);

        $detail = DispatchDetail::where('dispatch_id', $dispatch2->id)->first();
        $this->assertNull($detail->location_id, 'Service dispatch detail should have no location');
        $this->assertNull($detail->serial_numbers, 'Service dispatch detail should have no serials');
        $this->assertEquals(2, $detail->dispatched_quantity);

        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('dispatches.approve', $dispatch2));

        $sale->refresh();
        $this->assertEquals(Sale::STATUS_DISPATCHED, $sale->status, 'Sale should be DISPATCHED after service approval');

        $serviceProduct->refresh();
        $this->assertEquals(0, $serviceProduct->product_quantity, 'Service product quantity should not change (already 0 for non-stock)');
    }

    public function test_stock_managed_non_serial_product_without_location_rejected(): void
    {
        [$setting, $user, $sale, $_, $stockProduct, $location] = $this->createSetup(mixedProducts: true);

        // Try to submit stock-managed product without location
        $stockKey = $stockProduct->id . '--0';
        $payload = [
            'dispatch_date' => now()->toDateString(),
            'dispatchedQuantities' => [$stockKey => 1],
            // Missing selectedLocations for stock product
        ];

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('sales.storeDispatch', $sale), $payload);

        $response->assertSessionHasErrors("selectedLocations.$stockKey");
        $this->assertNull(Dispatch::where('sale_id', $sale->id)->first());
    }

    public function test_service_bundle_with_stock_managed_component_dispatch_aggregation(): void
    {
        // Non-stock service as bundle parent with stock-managed component
        $setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'company@example.com',
            'company_phone' => '1234567890',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => '123 Testing Lane',
        ]);

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

        // Non-stock service parent
        $laptopService = Product::create([
            'product_name' => 'Laptop Service',
            'product_code' => 'LSVC-001',
            'setting_id' => $setting->id,
            'product_quantity' => 0,
            'product_cost' => 0,
            'stock_managed' => false,
            'product_price' => 500000,
            'category_id' => $category->id,
            'product_unit' => $unit->id,
        ]);

        // Stock-managed RAM component
        $ramReplacement = Product::create([
            'product_name' => 'RAM Replacement',
            'product_code' => 'RAM-001',
            'setting_id' => $setting->id,
            'product_quantity' => 10,
            'product_cost' => 100000,
            'stock_managed' => true,
            'product_price' => 150000,
            'category_id' => $category->id,
            'product_unit' => $unit->id,
        ]);

        // RAM stock
        ProductStock::create([
            'product_id' => $ramReplacement->id,
            'location_id' => $location->id,
            'quantity' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Create Sale: 2× Laptop Service bundles with 1× RAM each
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
            'total_amount' => 1000000,
            'paid_amount' => 0,
            'due_amount' => 1000000,
            'status' => 'Approved',
            'payment_status' => 'Unpaid',
            'payment_method' => 'cash',
            'payment_term_id' => $paymentTerm->id,
            'setting_id' => $setting->id,
            'is_tax_included' => false,
            'reference' => 'SO-BUNDLE-001'
        ]);

        // Parent service line: 2× Laptop Service
        $parentDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $laptopService->id,
            'product_name' => $laptopService->product_name,
            'product_code' => $laptopService->product_code,
            'quantity' => 2,
            'price' => 500000,
            'unit_price' => 500000,
            'sub_total' => 1000000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        // Bundle component: 2× RAM (1 per service bundle)
        SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $parentDetail->id,
            'product_id' => $ramReplacement->id,
            'bundle_id' => 1,
            'bundle_item_id' => 1,
            'name' => $ramReplacement->product_name,
            'quantity' => 2,  // 1 per bundle parent × 2 parents
            'price' => 0,
            'sub_total' => 0,
        ]);

        // VERIFY: Dispatch aggregation shows two independent obligations
        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('sales.dispatch', $sale));
        $response->assertStatus(200);

        $aggregated = $response->viewData('aggregatedProducts');
        $this->assertNotEmpty($aggregated, 'Aggregation should have products');

        // Laptop Service acknowledgement: quantity 2, no location/serial
        // Composite key format: product_id - tax_id - bundle_id
        $serviceKey = $laptopService->id . '-' . null . '-0';
        $this->assertArrayHasKey($serviceKey, $aggregated, "Service key $serviceKey not found in aggregation");
        $this->assertEquals(2, $aggregated[$serviceKey]['total_quantity']);
        $this->assertFalse($aggregated[$serviceKey]['is_inventory_managed'], 'Service should not be inventory-managed');

        // RAM Replacement inventory: quantity 2, requires location
        // Bundle components use bundle_id from the bundle item
        $ramKey = $ramReplacement->id . '-' . null . '-1'; // bundle_id=1
        $this->assertArrayHasKey($ramKey, $aggregated, "RAM key $ramKey not found in aggregation");
        $this->assertEquals(2, $aggregated[$ramKey]['total_quantity']);
        $this->assertTrue($aggregated[$ramKey]['is_inventory_managed'], 'RAM should be inventory-managed');

        // STEP 1: Submit RAM quantity 2 with location through storeDispatch
        $ramKey = $ramReplacement->id . '--1'; // composite key: product_id--bundle_id
        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('sales.storeDispatch', $sale), [
                'dispatch_date' => now()->toDateString(),
                'dispatchedQuantities' => [
                    $ramKey => 2,
                ],
                'selectedLocations' => [
                    $ramKey => $location->id,
                ],
                'acknowledge_lifecycle_warning' => true,
            ]);

        $response->assertRedirect(route('sales.dispatches.index'));

        // Retrieve the created Dispatch
        $ramDispatch = Dispatch::where('sale_id', $sale->id)->first();
        $this->assertNotNull($ramDispatch);
        $this->assertEquals(Dispatch::STATUS_PENDING, $ramDispatch->status);

        // Verify RAM detail exists and is correct
        $ramDetails = DispatchDetail::where('dispatch_id', $ramDispatch->id)->get();
        $this->assertEquals(1, $ramDetails->count(), 'Should have exactly one detail (RAM only)');

        $ramDetail = $ramDetails->first();
        $this->assertEquals($ramReplacement->id, $ramDetail->product_id);
        $this->assertEquals(2, $ramDetail->dispatched_quantity);
        $this->assertEquals(1, $ramDetail->bundle_id);
        $this->assertEquals($location->id, $ramDetail->location_id);

        // Verify no service detail yet
        $serviceDetails = DispatchDetail::where('dispatch_id', $ramDispatch->id)
            ->where('product_id', $laptopService->id)
            ->get();
        $this->assertEquals(0, $serviceDetails->count(), 'Should not have service detail before service submission');

        // Approve RAM dispatch
        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('dispatches.approve', $ramDispatch), [
                'acknowledge_lifecycle_warning' => true,
            ]);

        $ramDispatch->refresh();
        $this->assertEquals(Dispatch::STATUS_APPROVED, $ramDispatch->status);

        // Verify RAM stock decreased by 2
        $ramReplacement->refresh();
        $this->assertEquals(8, $ramReplacement->product_quantity, 'RAM global quantity should decrease by 2');

        $locationStock = ProductStock::where('product_id', $ramReplacement->id)
            ->where('location_id', $location->id)
            ->first();
        $this->assertEquals(8, $locationStock->quantity_non_tax, 'RAM location stock should decrease by 2');

        // Sale should be DISPATCHED_PARTIALLY (service not yet approved)
        $sale->refresh();
        $this->assertEquals(Sale::STATUS_DISPATCHED_PARTIALLY, $sale->status);

        // STEP 2: Submit Laptop Service quantity 2 with no location/serial through storeDispatch
        $serviceKey = $laptopService->id . '--0'; // composite key: product_id--bundle_id
        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('sales.storeDispatch', $sale), [
                'dispatch_date' => now()->toDateString(),
                'dispatchedQuantities' => [
                    $serviceKey => 2,
                ],
                'acknowledge_lifecycle_warning' => true,
            ]);

        $response->assertRedirect(route('sales.dispatches.index'));

        // Retrieve the second Dispatch
        $serviceDispatch = Dispatch::where('sale_id', $sale->id)
            ->where('id', '!=', $ramDispatch->id)
            ->first();
        $this->assertNotNull($serviceDispatch);
        $this->assertEquals(Dispatch::STATUS_PENDING, $serviceDispatch->status);

        // Verify service detail exists and is correct
        $serviceDetails = DispatchDetail::where('dispatch_id', $serviceDispatch->id)->get();
        $this->assertEquals(1, $serviceDetails->count(), 'Should have exactly one detail (service only)');

        $serviceDetail = $serviceDetails->first();
        $this->assertEquals($laptopService->id, $serviceDetail->product_id);
        $this->assertEquals(2, $serviceDetail->dispatched_quantity);
        $this->assertNull($serviceDetail->location_id, 'Service detail should have null location');
        $this->assertNull($serviceDetail->serial_numbers, 'Service detail should have null serial_numbers');

        // Approve service dispatch
        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('dispatches.approve', $serviceDispatch), [
                'acknowledge_lifecycle_warning' => true,
            ]);

        $serviceDispatch->refresh();
        $this->assertEquals(Dispatch::STATUS_APPROVED, $serviceDispatch->status);

        // Verify no inventory transaction for service
        $serviceTransactions = \Modules\Product\Entities\Transaction::where('product_id', $laptopService->id)->count();
        $this->assertEquals(0, $serviceTransactions, 'No inventory transactions for non-stock service');

        // Verify service product quantity unchanged (already 0)
        $laptopService->refresh();
        $this->assertEquals(0, $laptopService->product_quantity);

        // Sale should now be DISPATCHED
        $sale->refresh();
        $this->assertEquals(Sale::STATUS_DISPATCHED, $sale->status);
    }
}
