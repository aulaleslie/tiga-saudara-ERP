<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Sale;
use Tests\TestCase;
use Gloudemans\Shoppingcart\Facades\Cart;
use Livewire\Livewire;
use App\Livewire\Sale\CreateForm;
use App\Livewire\Sale\SearchProduct;
use Modules\Setting\Entities\Setting;

class SaleNonStockProductTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Customer $customer;
    protected PaymentTerm $paymentTerm;

    protected function setUp(): void
    {
        parent::setUp();

        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);

        Gate::before(fn() => true);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '12345',
            'notification_email' => 'notify@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'footer',
            'company_address' => 'address'
        ]);
        session(['setting_id' => $this->setting->id]);

        $this->customer = Customer::factory()->create(['setting_id' => $this->setting->id]);
        $this->paymentTerm = PaymentTerm::create(['name' => 'Cash', 'longevity' => 0]);

        Cart::instance('sale')->destroy();
    }

    protected function tearDown(): void
    {
        Cart::instance('sale')->destroy();
        parent::tearDown();
    }

    private function createProduct($name, $code, $stockManaged, $quantity = 0, $cost = 0, $price = 0)
    {
        return Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => $name,
            'product_code' => $code,
            'product_quantity' => $quantity,
            'product_cost' => $cost,
            'product_price' => $price,
            'is_sold' => true,
            'stock_managed' => $stockManaged,
        ]);
    }

    public function test_non_stock_product_search_returns_searchable_non_stock_product()
    {
        $nonStockProduct = $this->createProduct('Repair Service', 'SVC-001', false);

        $response = $this->getJson('/api/products/search?query=Repair');

        $response->assertStatus(200);
        $products = $response->json();
        $this->assertNotEmpty($products);
        $this->assertTrue(collect($products)->contains(fn($p) => $p['product_name'] === 'Repair Service'));
    }

    public function test_non_saleable_product_excluded_from_sales_search()
    {
        $nonSaleableProduct = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Not Saleable',
            'product_code' => 'NSALE-001',
            'product_quantity' => 10,
            'product_cost' => 100,
            'product_price' => 100,
            'is_sold' => false,
            'stock_managed' => true,
        ]);

        $response = $this->getJson('/api/products/search?query=Not%20Saleable');

        $response->assertStatus(200);
        $products = $response->json();
        $this->assertEmpty(collect($products)->filter(fn($p) => $p['product_code'] === 'NSALE-001'));
    }

    public function test_non_stock_product_added_to_sales_cart()
    {
        $serviceProduct = $this->createProduct('Installation Service', 'INST-001', false);

        ProductPrice::create([
            'product_id' => $serviceProduct->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 500000,
        ]);

        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => 'service_item',
            'name' => $serviceProduct->product_name,
            'qty' => 1,
            'price' => 500000,
            'weight' => 1,
            'options' => [
                'product_id' => $serviceProduct->id,
                'code' => $serviceProduct->product_code,
                'unit_price' => 500000,
                'sub_total' => 500000,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => null,
                'bundle_items' => []
            ]
        ]);

        $this->assertCount(1, Cart::instance('sale')->content());
        $this->assertEquals(500000, Cart::instance('sale')->subtotal());
    }

    public function test_service_only_sale_creation_succeeds_with_no_inventory()
    {
        $serviceProduct = $this->createProduct('Consulting Service', 'CONS-001', false);

        ProductPrice::create([
            'product_id' => $serviceProduct->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 1000000,
        ]);

        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => 'consulting',
            'name' => $serviceProduct->product_name,
            'qty' => 1,
            'price' => 1000000,
            'weight' => 1,
            'options' => [
                'product_id' => $serviceProduct->id,
                'code' => $serviceProduct->product_code,
                'unit_price' => 1000000,
                'sub_total' => 1000000,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => null,
                'bundle_items' => []
            ]
        ]);

        $response = $this->post(route('sales.store'), [
            'customer_id' => $this->customer->id,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'payment_term_id' => $this->paymentTerm->id,
            'shipping_amount' => 0,
            'total_amount' => 1000000,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'is_tax_included' => false,
        ]);

        $response->assertRedirect(route('sales.index'));
        $this->assertEquals(1, Sale::count());

        $sale = Sale::first();
        $this->assertEquals(1000000, $sale->total_amount);
        $this->assertEquals(1, $sale->saleDetails->count());
        $this->assertEquals($serviceProduct->id, $sale->saleDetails->first()->product_id);
    }

    public function test_mixed_sale_rejects_unavailable_physical_goods()
    {
        $serviceProduct = $this->createProduct('Service', 'SVC-002', false);
        $physicalProduct = $this->createProduct('Physical Product', 'PHY-001', true, 5, 50);

        ProductPrice::create([
            'product_id' => $serviceProduct->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 500000,
        ]);

        ProductPrice::create([
            'product_id' => $physicalProduct->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 100000,
        ]);

        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => 'service_item',
            'name' => $serviceProduct->product_name,
            'qty' => 1,
            'price' => 500000,
            'weight' => 1,
            'options' => [
                'product_id' => $serviceProduct->id,
                'code' => $serviceProduct->product_code,
                'unit_price' => 500000,
                'sub_total' => 500000,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => null,
                'bundle_items' => []
            ]
        ]);

        Cart::instance('sale')->add([
            'id' => 'physical_item',
            'name' => $physicalProduct->product_name,
            'qty' => 10,
            'price' => 100000,
            'weight' => 1,
            'options' => [
                'product_id' => $physicalProduct->id,
                'code' => $physicalProduct->product_code,
                'unit_price' => 100000,
                'sub_total' => 1000000,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => null,
                'bundle_items' => []
            ]
        ]);

        $response = $this->post(route('sales.store'), [
            'customer_id' => $this->customer->id,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'payment_term_id' => $this->paymentTerm->id,
            'shipping_amount' => 0,
            'total_amount' => 1500000,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'is_tax_included' => false,
        ]);

        $response->assertSessionHasErrors('cart');
        $this->assertEquals(0, Sale::count());
    }

    public function test_non_stock_product_receives_zero_cost_snapshot()
    {
        $serviceProduct = $this->createProduct('Service', 'SVC-003', false);

        ProductPrice::create([
            'product_id' => $serviceProduct->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 250000,
        ]);

        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => 'service',
            'name' => $serviceProduct->product_name,
            'qty' => 1,
            'price' => 250000,
            'weight' => 1,
            'options' => [
                'product_id' => $serviceProduct->id,
                'code' => $serviceProduct->product_code,
                'unit_price' => 250000,
                'sub_total' => 250000,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => null,
                'bundle_items' => []
            ]
        ]);

        $response = $this->post(route('sales.store'), [
            'customer_id' => $this->customer->id,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'payment_term_id' => $this->paymentTerm->id,
            'shipping_amount' => 0,
            'total_amount' => 250000,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'is_tax_included' => false,
        ]);

        $response->assertRedirect(route('sales.index'));
        $sale = Sale::first();
        $saleDetail = $sale->saleDetails->first();

        $this->assertNotNull($saleDetail);
        $this->assertEquals(0, $saleDetail->cost_unit_snapshot);
        $this->assertEquals(0, $saleDetail->cost_total_snapshot);
    }

    public function test_empty_dispatch_quantities_are_rejected()
    {
        $serviceProduct = $this->createProduct('Service Only', 'SVC-DISP-001', false);

        ProductPrice::create([
            'product_id' => $serviceProduct->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 500000,
        ]);

        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => 'service_item',
            'name' => $serviceProduct->product_name,
            'qty' => 1,
            'price' => 500000,
            'weight' => 1,
            'options' => [
                'product_id' => $serviceProduct->id,
                'code' => $serviceProduct->product_code,
                'unit_price' => 500000,
                'sub_total' => 500000,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => null,
                'bundle_items' => []
            ]
        ]);

        $response = $this->post(route('sales.store'), [
            'customer_id' => $this->customer->id,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'payment_term_id' => $this->paymentTerm->id,
            'shipping_amount' => 0,
            'total_amount' => 500000,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'is_tax_included' => false,
        ]);

        $response->assertRedirect(route('sales.index'));
        $sale = Sale::first();

        // Attempt to dispatch with empty quantities
        $response = $this->post(route('sales.storeDispatch', $sale), [
            'dispatch_date' => now()->format('Y-m-d'),
            'dispatchedQuantities' => [],
            'selectedLocations' => [],
            'selectedSerialNumbers' => [],
            'serialNumberLocations' => [],
        ]);

        $response->assertSessionHasErrors();
        $this->assertEquals(0, \Modules\Sale\Entities\Dispatch::count());
        $this->assertEquals(0, \Modules\Sale\Entities\DispatchDetail::count());
    }

    public function test_mixed_dispatch_can_include_non_stock_acknowledgement_and_stock_detail()
    {
        $serviceProduct = $this->createProduct('Service', 'SVC-EXCL-001', false);
        $physicalProduct = $this->createProduct('Physical', 'PHY-EXCL-001', true, 10, 50000);

        ProductPrice::create([
            'product_id' => $serviceProduct->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 500000,
        ]);

        ProductPrice::create([
            'product_id' => $physicalProduct->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 100000,
        ]);

        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => 'service',
            'name' => $serviceProduct->product_name,
            'qty' => 1,
            'price' => 500000,
            'weight' => 1,
            'options' => [
                'product_id' => $serviceProduct->id,
                'code' => $serviceProduct->product_code,
                'unit_price' => 500000,
                'sub_total' => 500000,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => null,
                'bundle_items' => []
            ]
        ]);

        Cart::instance('sale')->add([
            'id' => 'physical',
            'name' => $physicalProduct->product_name,
            'qty' => 5,
            'price' => 100000,
            'weight' => 1,
            'options' => [
                'product_id' => $physicalProduct->id,
                'code' => $physicalProduct->product_code,
                'unit_price' => 100000,
                'sub_total' => 500000,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => null,
                'bundle_items' => []
            ]
        ]);

        $response = $this->post(route('sales.store'), [
            'customer_id' => $this->customer->id,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'payment_term_id' => $this->paymentTerm->id,
            'shipping_amount' => 0,
            'total_amount' => 1000000,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'is_tax_included' => false,
        ]);

        $response->assertRedirect(route('sales.index'));
        $sale = Sale::first();

        // Mock location and stock for the physical product
        $location = \Modules\Setting\Entities\Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Main Warehouse',
        ]);

        \Modules\Product\Entities\ProductStock::create([
            'product_id' => $physicalProduct->id,
            'location_id' => $location->id,
            'quantity' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Submit both service and physical quantities
        $response = $this->post(route('sales.storeDispatch', $sale), [
            'dispatch_date' => now()->format('Y-m-d'),
            'dispatchedQuantities' => [
                $serviceProduct->id . '--0' => '1',
                $physicalProduct->id . '--0' => '5',
            ],
            'selectedLocations' => [
                $physicalProduct->id . '--0' => $location->id,
            ],
            'selectedSerialNumbers' => [],
            'serialNumberLocations' => [],
        ]);

        $response->assertRedirect(route('sales.dispatches.index'));
        $this->assertEquals(1, \Modules\Sale\Entities\Dispatch::count());

        $dispatch = \Modules\Sale\Entities\Dispatch::first();
        $details = $dispatch->details;

        // Should have dispatch details for both service and physical product
        $this->assertEquals(2, $details->count(), 'Dispatch should have both service and physical details');

        $serviceDetail = $details->where('product_id', $serviceProduct->id)->first();
        $this->assertNotNull($serviceDetail, 'Should have service dispatch detail');
        $this->assertNull($serviceDetail->location_id, 'Service dispatch detail should have no location');
        $this->assertNull($serviceDetail->serial_numbers, 'Service dispatch detail should have no serials');

        $physicalDetail = $details->where('product_id', $physicalProduct->id)->first();
        $this->assertNotNull($physicalDetail, 'Should have physical product detail');
        $this->assertEquals($location->id, $physicalDetail->location_id, 'Physical detail should retain selected location');
    }

    public function test_mixed_sale_reaches_dispatched_after_all_stock_managed_lines_approved()
    {
        $serviceProduct = $this->createProduct('Service', 'SVC-MIXED-001', false);
        $physicalProduct = $this->createProduct('Physical', 'PHY-MIXED-001', true, 10, 50000);

        ProductPrice::create([
            'product_id' => $serviceProduct->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 500000,
        ]);

        ProductPrice::create([
            'product_id' => $physicalProduct->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 100000,
        ]);

        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => 'service',
            'name' => $serviceProduct->product_name,
            'qty' => 1,
            'price' => 500000,
            'weight' => 1,
            'options' => [
                'product_id' => $serviceProduct->id,
                'code' => $serviceProduct->product_code,
                'unit_price' => 500000,
                'sub_total' => 500000,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => null,
                'bundle_items' => []
            ]
        ]);

        Cart::instance('sale')->add([
            'id' => 'physical',
            'name' => $physicalProduct->product_name,
            'qty' => 5,
            'price' => 100000,
            'weight' => 1,
            'options' => [
                'product_id' => $physicalProduct->id,
                'code' => $physicalProduct->product_code,
                'unit_price' => 100000,
                'sub_total' => 500000,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => null,
                'bundle_items' => []
            ]
        ]);

        $response = $this->post(route('sales.store'), [
            'customer_id' => $this->customer->id,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'payment_term_id' => $this->paymentTerm->id,
            'shipping_amount' => 0,
            'total_amount' => 1000000,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'is_tax_included' => false,
        ]);

        $response->assertRedirect(route('sales.index'));
        $sale = Sale::first();
        $this->assertEquals(\Modules\Sale\Entities\Sale::STATUS_DRAFTED, $sale->status);

        // Setup location and stock
        $location = \Modules\Setting\Entities\Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Main Warehouse',
        ]);

        \Modules\Product\Entities\ProductStock::create([
            'product_id' => $physicalProduct->id,
            'location_id' => $location->id,
            'quantity' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Dispatch the physical product
        $response = $this->post(route('sales.storeDispatch', $sale), [
            'dispatch_date' => now()->format('Y-m-d'),
            'dispatchedQuantities' => [
                $physicalProduct->id . '--0' => '5',
            ],
            'selectedLocations' => [
                $physicalProduct->id . '--0' => $location->id,
            ],
            'selectedSerialNumbers' => [],
            'serialNumberLocations' => [],
        ]);

        $response->assertRedirect(route('sales.dispatches.index'));
        $dispatch = \Modules\Sale\Entities\Dispatch::first();
        $this->assertEquals(\Modules\Sale\Entities\Dispatch::STATUS_PENDING, $dispatch->status);

        // Approve the dispatch
        $response = $this->post(route('dispatches.approve', $dispatch));
        $response->assertRedirect();

        $dispatch->refresh();
        $this->assertEquals(\Modules\Sale\Entities\Dispatch::STATUS_APPROVED, $dispatch->status);

        // Sale should now be DISPATCHED_PARTIALLY because the service product still needs acknowledgement
        $sale->refresh();
        $this->assertEquals(\Modules\Sale\Entities\Sale::STATUS_DISPATCHED_PARTIALLY, $sale->status);

        // Now dispatch and approve the service product
        $serviceProduct->refresh();
        $response = $this->post(route('sales.storeDispatch', $sale), [
            'dispatch_date' => now()->format('Y-m-d'),
            'dispatchedQuantities' => [
                $serviceProduct->id . '--0' => '1',
            ],
        ]);

        $response->assertRedirect(route('sales.dispatches.index'));
        $dispatch2 = \Modules\Sale\Entities\Dispatch::latest('id')->first();
        $this->assertEquals(\Modules\Sale\Entities\Dispatch::STATUS_PENDING, $dispatch2->status);

        // Approve the service dispatch
        $response = $this->post(route('dispatches.approve', $dispatch2));
        $response->assertRedirect();

        $dispatch2->refresh();
        $this->assertEquals(\Modules\Sale\Entities\Dispatch::STATUS_APPROVED, $dispatch2->status);

        // Now sale should be DISPATCHED
        $sale->refresh();
        $this->assertEquals(\Modules\Sale\Entities\Sale::STATUS_DISPATCHED, $sale->status);
    }

    public function test_true_stock_managed_product_validates_stock()
    {
        $trueStockProduct = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'True Stock Managed Product',
            'product_code' => 'TRUE-STOCK-001',
            'product_quantity' => 5,
            'product_cost' => 50000,
            'product_price' => 100000,
            'is_sold' => true,
            'stock_managed' => true,
        ]);

        ProductPrice::create([
            'product_id' => $trueStockProduct->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 100000,
        ]);

        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => 'true_stock',
            'name' => $trueStockProduct->product_name,
            'qty' => 10,
            'price' => 100000,
            'weight' => 1,
            'options' => [
                'product_id' => $trueStockProduct->id,
                'code' => $trueStockProduct->product_code,
                'unit_price' => 100000,
                'sub_total' => 1000000,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => null,
                'bundle_items' => []
            ]
        ]);

        $response = $this->post(route('sales.store'), [
            'customer_id' => $this->customer->id,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'payment_term_id' => $this->paymentTerm->id,
            'shipping_amount' => 0,
            'total_amount' => 1000000,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'is_tax_included' => false,
        ]);

        $response->assertSessionHasErrors('cart');
        $this->assertEquals(0, Sale::count());
    }

    public function test_explicitly_false_stock_managed_product_bypasses_stock_validation()
    {
        $nonStockProduct = $this->createProduct('Non-Stock Service', 'NSTK-BYPASS-001', false, 0, 0);

        ProductPrice::create([
            'product_id' => $nonStockProduct->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 500000,
        ]);

        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => 'non_stock_service',
            'name' => $nonStockProduct->product_name,
            'qty' => 100,
            'price' => 500000,
            'weight' => 1,
            'options' => [
                'product_id' => $nonStockProduct->id,
                'code' => $nonStockProduct->product_code,
                'unit_price' => 500000,
                'sub_total' => 50000000,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => null,
                'bundle_items' => []
            ]
        ]);

        // Should succeed even though quantity far exceeds zero
        $response = $this->post(route('sales.store'), [
            'customer_id' => $this->customer->id,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'payment_term_id' => $this->paymentTerm->id,
            'shipping_amount' => 0,
            'total_amount' => 50000000,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'is_tax_included' => false,
        ]);

        $response->assertRedirect(route('sales.index'));
        $this->assertEquals(1, Sale::count());

        $sale = Sale::first();
        $this->assertEquals(1, $sale->saleDetails->count());
        $this->assertEquals(100, $sale->saleDetails->first()->quantity);
    }

    public function test_linked_bundle_component_dispatch_page_loads_without_error()
    {
        $bundleParent = $this->createProduct('Bundle Parent', 'BP-001', true, 5, 1000, 2000);
        $bundleComponent = $this->createProduct('Bundle Component', 'COMP-001', true, 10, 100, 0);

        $location = \Modules\Setting\Entities\Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Warehouse',
        ]);

        \Modules\Product\Entities\ProductStock::create([
            'product_id' => $bundleComponent->id,
            'location_id' => $location->id,
            'quantity' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Create a sale directly with parent and linked bundle item
        $sale = Sale::create([
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'cash',
            'payment_term_id' => $this->paymentTerm->id,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'reference' => 'SO-LINKED-BUNDLE',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
        ]);

        // Create sale detail for the parent
        $saleDetail = \Modules\Sale\Entities\SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $bundleParent->id,
            'product_name' => $bundleParent->product_name,
            'product_code' => $bundleParent->product_code,
            'quantity' => 1,
            'price' => 2000,
            'unit_price' => 2000,
            'sub_total' => 2000,
            'tax_id' => null,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 1000,
            'cost_total_snapshot' => 1000,
        ]);

        // Create linked bundle item (sale_detail_id is set, so it inherits tax from parent)
        \Modules\Sale\Entities\SaleBundleItem::create([
            'sale_id' => $sale->id,
            'sale_detail_id' => $saleDetail->id,
            'product_id' => $bundleComponent->id,
            'name' => $bundleComponent->product_name,
            'quantity' => 3,
            'price' => 0,
            'sub_total' => 0,
            'bundle_id' => 1,
            'bundle_item_id' => 1,
        ]);

        // Key test: dispatch page should load without database-column error (inherited_tax_id accessor issue)
        $response = $this->get(route('sales.dispatch', $sale));
        $response->assertStatus(200);

        $aggregatedProducts = $response->viewData('aggregatedProducts');
        // Should have the bundle component in aggregation, using parent tax context (null)
        $this->assertNotEmpty($aggregatedProducts);
    }

    public function test_standalone_bundle_item_dispatch_page_uses_stored_tax_context()
    {
        $bundleComponent = $this->createProduct('Standalone Component', 'COMP-STANDALONE', true, 10, 100, 500);
        $tax = \Modules\Setting\Entities\Tax::create(['name' => 'PPN 11%', 'value' => 11]);

        $location = \Modules\Setting\Entities\Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Warehouse',
        ]);

        \Modules\Product\Entities\ProductStock::create([
            'product_id' => $bundleComponent->id,
            'location_id' => $location->id,
            'quantity' => 10,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Create sale with standalone bundle item
        $sale = Sale::create([
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'total_amount' => 500,
            'paid_amount' => 0,
            'due_amount' => 500,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'cash',
            'payment_term_id' => $this->paymentTerm->id,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'reference' => 'SO-STANDALONE-BUNDLE',
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
        ]);

        // Create standalone bundle item with own tax context
        \Modules\Sale\Entities\SaleBundleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $bundleComponent->id,
            'name' => $bundleComponent->product_name,
            'quantity' => 2,
            'price' => 500,
            'sub_total' => 1000,
            'tax_id' => $tax->id,
            'tax_amount' => 0,
            'bundle_id' => 1,
            'bundle_item_id' => 1,
            'line_group_key' => 'standalone-1-' . $bundleComponent->id,
        ]);

        // Dispatch page should load and show standalone item with its own tax context
        $response = $this->get(route('sales.dispatch', $sale));
        $response->assertStatus(200);

        $aggregatedProducts = $response->viewData('aggregatedProducts');
        $key = $bundleComponent->id . '-' . $tax->id . '-1';
        $this->assertArrayHasKey($key, $aggregatedProducts);
        $this->assertEquals($tax->id, $aggregatedProducts[$key]['tax_id']);
    }

    public function test_forged_composite_key_is_rejected_on_dispatch()
    {
        $product = $this->createProduct('Physical', 'PHYS-001', true, 10, 1000, 2000);
        $tax = \Modules\Setting\Entities\Tax::create(['name' => 'PPN 11%', 'value' => 11]);

        $location = \Modules\Setting\Entities\Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Warehouse',
        ]);

        \Modules\Product\Entities\ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 10,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => 'product_item',
            'name' => $product->product_name,
            'qty' => 5,
            'price' => 2000,
            'weight' => 1,
            'options' => [
                'product_id' => $product->id,
                'code' => $product->product_code,
                'unit_price' => 2000,
                'sub_total' => 10000,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => null,
                'bundle_items' => []
            ]
        ]);

        $response = $this->post(route('sales.store'), [
            'customer_id' => $this->customer->id,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'payment_term_id' => $this->paymentTerm->id,
            'shipping_amount' => 0,
            'total_amount' => 10000,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'is_tax_included' => false,
        ]);

        $response->assertRedirect(route('sales.index'));
        $sale = Sale::first();

        // Try to dispatch with a forged tax/bundle composite key
        $forgedKey = $product->id . '-' . $tax->id . '-99'; // Forged bundle key

        $response = $this->post(route('sales.storeDispatch', $sale), [
            'dispatch_date' => now()->format('Y-m-d'),
            'dispatchedQuantities' => [
                $forgedKey => '3',
            ],
            'selectedLocations' => [
                $forgedKey => $location->id,
            ],
            'selectedSerialNumbers' => [],
            'serialNumberLocations' => [],
        ]);

        // Should reject with validation error
        $response->assertSessionHasErrors();
        $this->assertEquals(0, \Modules\Sale\Entities\Dispatch::count());
        $this->assertEquals(0, \Modules\Sale\Entities\DispatchDetail::count());
    }

    public function test_mixed_valid_and_forged_keys_rejected()
    {
        $nonStockProduct = $this->createProduct('Service', 'SVC-MIX-001', false);
        $stockProduct = $this->createProduct('Physical', 'PHYS-MIX-001', true, 10, 1000, 2000);

        $location = \Modules\Setting\Entities\Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Warehouse',
        ]);

        \Modules\Product\Entities\ProductStock::create([
            'product_id' => $stockProduct->id,
            'location_id' => $location->id,
            'quantity' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => 'service',
            'name' => $nonStockProduct->product_name,
            'qty' => 1,
            'price' => 500000,
            'weight' => 1,
            'options' => [
                'product_id' => $nonStockProduct->id,
                'code' => $nonStockProduct->product_code,
                'unit_price' => 500000,
                'sub_total' => 500000,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => null,
                'bundle_items' => []
            ]
        ]);

        Cart::instance('sale')->add([
            'id' => 'physical',
            'name' => $stockProduct->product_name,
            'qty' => 5,
            'price' => 2000,
            'weight' => 1,
            'options' => [
                'product_id' => $stockProduct->id,
                'code' => $stockProduct->product_code,
                'unit_price' => 2000,
                'sub_total' => 10000,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => null,
                'bundle_items' => []
            ]
        ]);

        $response = $this->post(route('sales.store'), [
            'customer_id' => $this->customer->id,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'payment_term_id' => $this->paymentTerm->id,
            'shipping_amount' => 0,
            'total_amount' => 510000,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'is_tax_included' => false,
        ]);

        $response->assertRedirect(route('sales.index'));
        $sale = Sale::first();

        // Try to submit with non-stock key AND forged stock-managed key
        $response = $this->post(route('sales.storeDispatch', $sale), [
            'dispatch_date' => now()->format('Y-m-d'),
            'dispatchedQuantities' => [
                $nonStockProduct->id . '--0' => '1', // Non-stock key (should be skipped)
                $stockProduct->id . '--99' => '5', // Forged stock-managed key
            ],
            'selectedLocations' => [
                $nonStockProduct->id . '--0' => $location->id,
                $stockProduct->id . '--99' => $location->id,
            ],
            'selectedSerialNumbers' => [],
            'serialNumberLocations' => [],
        ]);

        // Should reject because forged key is not in aggregated demand
        $response->assertSessionHasErrors();
        $this->assertEquals(0, \Modules\Sale\Entities\Dispatch::count());
        $this->assertEquals(0, \Modules\Sale\Entities\DispatchDetail::count());
    }
}
