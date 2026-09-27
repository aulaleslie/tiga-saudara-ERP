<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Services\SaleService;
use Tests\TestCase;
use Gloudemans\Shoppingcart\Facades\Cart;
use Livewire\Livewire;
use App\Livewire\Sale\CreateForm;
use App\Models\User;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;

class SaleStockValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);

        // Required permissions
        Gate::define('sales.create', fn() => true);
        Gate::define('sales.access', fn() => true);

        // Standard setup
        $setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '12345',
            'notification_email' => 'notify@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'footer',
            'company_address' => 'address'
        ]);
        session(['setting_id' => $setting->id]);
    }

    public function test_standard_sale_creation_succeeds_when_stock_insufficient()
    {
        $customer = Customer::factory()->create(['setting_id' => session('setting_id')]);
        $paymentTerm = PaymentTerm::create(['name' => 'Cash', 'longevity' => 0]);
        $product = Product::create([
            'setting_id' => session('setting_id'),
            'product_name' => 'Test Product',
            'product_code' => 'PRD-001',
            'product_quantity' => 10,
            'product_cost' => 50,
            'product_price' => 100
        ]);

        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => 'test_item',
            'name' => $product->product_name,
            'qty' => 11,
            'price' => 100,
            'weight' => 1,
            'options' => [
                'product_id' => $product->id,
                'code' => $product->product_code,
                'unit_price' => 100,
                'sub_total' => 1100,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => null,
                'bundle_items' => []
            ]
        ]);

        $response = $this->post(route('sales.store'), [
            'customer_id' => $customer->id,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'payment_term_id' => $paymentTerm->id,
            'shipping_amount' => 0,
            'total_amount' => 1100,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'is_tax_included' => false,
        ]);

        $response->assertRedirect(route('sales.index'));
        $this->assertEquals(1, Sale::count());
        $product->refresh();
        $this->assertEquals(10, $product->product_quantity);
    }

    public function test_standard_sale_creation_succeeds_when_stock_sufficient()
    {
        $customer = Customer::factory()->create(['setting_id' => session('setting_id')]);
        $paymentTerm = PaymentTerm::create(['name' => 'Cash', 'longevity' => 0]);
        $product = Product::create([
            'setting_id' => session('setting_id'),
            'product_name' => 'Test Product',
            'product_code' => 'PRD-002',
            'product_quantity' => 10,
            'product_cost' => 50,
            'product_price' => 100
        ]);

        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => 'test_item',
            'name' => $product->product_name,
            'qty' => 10,
            'price' => 100,
            'weight' => 1,
            'options' => [
                'product_id' => $product->id,
                'code' => $product->product_code,
                'unit_price' => 100,
                'sub_total' => 1000,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => null,
                'bundle_items' => []
            ]
        ]);

        $response = $this->post(route('sales.store'), [
            'customer_id' => $customer->id,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'payment_term_id' => $paymentTerm->id,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'is_tax_included' => false,
        ]);

        $response->assertRedirect(route('sales.index'));
        $this->assertEquals(1, Sale::count());
    }

    public function test_bundle_sale_creation_succeeds_when_bundle_item_stock_insufficient()
    {
        $customer = Customer::factory()->create(['setting_id' => session('setting_id')]);
        $paymentTerm = PaymentTerm::create(['name' => 'Cash', 'longevity' => 0]);

        $parentProduct = Product::create([
            'setting_id' => session('setting_id'),
            'product_name' => 'Bundle Parent',
            'product_code' => 'BNDL-001',
            'product_quantity' => 100,
            'product_cost' => 100,
            'product_price' => 200
        ]);
        $bundleComponent = Product::create([
            'setting_id' => session('setting_id'),
            'product_name' => 'Component',
            'product_code' => 'COMP-001',
            'product_quantity' => 5,
            'product_cost' => 40,
            'product_price' => 50
        ]);

        $bundle = ProductBundle::create([
            'parent_product_id' => $parentProduct->id,
            'setting_id' => session('setting_id'),
            'name' => 'Test Bundle'
        ]);

        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $bundleComponent->id,
            'quantity' => 3
        ]);

        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => 'test_bundle',
            'name' => $parentProduct->product_name,
            'qty' => 2,
            'price' => 200,
            'weight' => 1,
            'options' => [
                'product_id' => $parentProduct->id,
                'code' => $parentProduct->product_code,
                'unit_price' => 200,
                'sub_total' => 400,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => null,
                'bundle_items' => [
                    [
                        'bundle_id' => $bundle->id,
                        'bundle_item_id' => 1,
                        'product_id' => $bundleComponent->id,
                        'name' => $bundleComponent->product_name,
                        'quantity' => 3,
                        'price' => 50,
                        'sub_total' => 150
                    ]
                ]
            ]
        ]);

        $response = $this->post(route('sales.store'), [
            'customer_id' => $customer->id,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'payment_term_id' => $paymentTerm->id,
            'shipping_amount' => 0,
            'total_amount' => 400,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'is_tax_included' => false,
        ]);

        $response->assertRedirect(route('sales.index'));
        $this->assertEquals(1, Sale::count());
        $parentProduct->refresh();
        $bundleComponent->refresh();
        $this->assertEquals(100, $parentProduct->product_quantity);
        $this->assertEquals(5, $bundleComponent->product_quantity);
    }

    public function test_zero_stock_product_sale_creation_persists_without_inventory_mutation()
    {
        $customer = Customer::factory()->create(['setting_id' => session('setting_id')]);
        $paymentTerm = PaymentTerm::create(['name' => 'Cash', 'longevity' => 0]);
        $product = Product::create([
            'setting_id' => session('setting_id'),
            'product_name' => 'Zero Stock Product',
            'product_code' => 'PRD-003',
            'product_quantity' => 0,
            'product_cost' => 50,
            'product_price' => 100
        ]);

        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => 'test_item',
            'name' => $product->product_name,
            'qty' => 5,
            'price' => 100,
            'weight' => 1,
            'options' => [
                'product_id' => $product->id,
                'code' => $product->product_code,
                'unit_price' => 100,
                'sub_total' => 500,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => null,
                'bundle_items' => []
            ]
        ]);

        $response = $this->post(route('sales.store'), [
            'customer_id' => $customer->id,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'payment_term_id' => $paymentTerm->id,
            'shipping_amount' => 0,
            'total_amount' => 500,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'is_tax_included' => false,
        ]);

        $response->assertRedirect(route('sales.index'));
        $this->assertEquals(1, Sale::count());
        $product->refresh();
        $this->assertEquals(0, $product->product_quantity);
    }

    public function test_livewire_standard_sale_creation_with_insufficient_stock()
    {
        $customer = Customer::factory()->create(['setting_id' => session('setting_id')]);
        $paymentTerm = PaymentTerm::create(['name' => 'Cash', 'longevity' => 0]);
        $product = Product::create([
            'setting_id' => session('setting_id'),
            'product_name' => 'Insufficient Stock Product',
            'product_code' => 'PRD-004',
            'product_quantity' => 5,
            'product_cost' => 50,
            'product_price' => 100,
            'stock_managed' => true,
        ]);

        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => 'test_item',
            'name' => $product->product_name,
            'qty' => 15,
            'price' => 100,
            'weight' => 1,
            'options' => [
                'product_id' => $product->id,
                'code' => $product->product_code,
                'unit_price' => 100,
                'sub_total' => 1500,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => null,
                'bundle_items' => []
            ]
        ]);

        $component = Livewire::test(CreateForm::class, ['idempotencyToken' => 'insufficient-stock-test'])
            ->set('customer_id', $customer->id)
            ->set('date', now()->format('Y-m-d'))
            ->set('due_date', now()->addDays(7)->format('Y-m-d'))
            ->set('payment_term_id', $paymentTerm->id)
            ->set('shipping_amount', 0)
            ->set('discount_percentage', 0)
            ->set('discount_amount', 0)
            ->set('is_tax_included', false)
            ->call('submit');

        $component->assertHasNoErrors();

        $this->assertEquals(1, Sale::count());
        $sale = Sale::first();
        $this->assertEquals(1, $sale->saleDetails()->count());

        $detail = $sale->saleDetails()->first();
        $this->assertEquals($product->id, $detail->product_id);
        $this->assertEquals(15, $detail->quantity);

        $product->refresh();
        $this->assertEquals(5, $product->product_quantity);

        // No inventory transaction should exist for this sale
        $transactionCount = Transaction::where('product_id', $product->id)->count();
        $this->assertEquals(0, $transactionCount);
    }

    public function test_dispatch_submission_rejects_insufficient_stock_at_location()
    {
        $setting = Setting::create([
            'company_name' => 'Dispatch Test Company',
            'company_email' => 'dispatch@example.com',
            'company_phone' => '9876543210',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'dispatch@example.com',
            'footer_text' => 'Footer',
            'company_address' => '456 Dispatch Lane',
        ]);

        $paymentTerm = PaymentTerm::create(['name' => 'Net 30', 'longevity' => 30]);
        $customer = Customer::factory()->create(['setting_id' => $setting->id, 'payment_term_id' => $paymentTerm->id]);

        $category = Category::create([
            'category_name' => 'Category',
            'category_code' => 'CAT',
            'setting_id' => $setting->id,
            'created_by' => 1
        ]);

        $unit = Unit::create([
            'name' => 'Unit',
            'short_name' => 'U',
            'operator' => '*',
            'operation_value' => 1,
            'setting_id' => $setting->id,
        ]);

        $product = Product::create([
            'product_name' => 'Stock Product',
            'product_code' => 'STOCK-001',
            'setting_id' => $setting->id,
            'product_quantity' => 100,
            'product_cost' => 1000,
            'stock_managed' => true,
            'product_price' => 1500,
            'category_id' => $category->id,
            'product_unit' => $unit->id,
        ]);

        $location = Location::create([
            'name' => 'Warehouse 1',
            'setting_id' => $setting->id,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 5,
            'quantity_tax' => 0,
            'quantity_non_tax' => 5,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity' => 0,
        ]);

        $user = User::factory()->create();
        Gate::define('sales.dispatch', fn() => true);

        // Create sale via SaleService with cart where requested quantity exceeds location stock
        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => 'test_item',
            'name' => $product->product_name,
            'qty' => 10,
            'price' => 1500,
            'weight' => 1,
            'options' => [
                'product_id' => $product->id,
                'code' => $product->product_code,
                'unit_price' => 1500,
                'sub_total' => 15000,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => null,
                'bundle_items' => []
            ]
        ]);

        $saleService = app(SaleService::class);
        $sale = $saleService->createSale([
            'customer_id' => $customer->id,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'payment_term_id' => $paymentTerm->id,
            'setting_id' => $setting->id,
        ], Cart::instance('sale')->content());

        $compositeKey = $product->id . '--0';
        $payload = [
            'dispatch_date' => now()->toDateString(),
            'dispatchedQuantities' => [$compositeKey => 10],
            'selectedLocations' => [$compositeKey => $location->id],
        ];

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('sales.storeDispatch', $sale), $payload);

        $response->assertRedirect();
        $response->assertSessionHasErrors("dispatchedQuantities.$compositeKey");

        $this->assertNull($sale->saleDispatches()->first());

        $product->refresh();
        $this->assertEquals(100, $product->product_quantity);

        $stock = ProductStock::where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->first();
        $this->assertEquals(5, $stock->quantity);

        // No inventory transaction should exist
        $transactionCount = Transaction::where('product_id', $product->id)->count();
        $this->assertEquals(0, $transactionCount);
    }

    public function test_editable_sale_update_with_insufficient_stock_bundle_component()
    {
        $customer = Customer::factory()->create(['setting_id' => session('setting_id')]);
        $paymentTerm = PaymentTerm::create(['name' => 'Cash', 'longevity' => 0]);

        $bundleParent = Product::create([
            'setting_id' => session('setting_id'),
            'product_name' => 'Bundle Parent V2',
            'product_code' => 'BNDL-002',
            'product_quantity' => 100,
            'product_cost' => 100,
            'product_price' => 200,
            'stock_managed' => true,
        ]);

        $bundleComponent = Product::create([
            'setting_id' => session('setting_id'),
            'product_name' => 'Scarce Component',
            'product_code' => 'COMP-002',
            'product_quantity' => 3,
            'product_cost' => 40,
            'product_price' => 50,
            'stock_managed' => true,
        ]);

        $bundle = ProductBundle::create([
            'parent_product_id' => $bundleParent->id,
            'setting_id' => session('setting_id'),
            'name' => 'Scarce Bundle'
        ]);

        ProductBundleItem::create([
            'bundle_id' => $bundle->id,
            'product_id' => $bundleComponent->id,
            'quantity' => 2
        ]);

        $saleService = app(SaleService::class);

        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => 'bundle_1',
            'name' => $bundleParent->product_name,
            'qty' => 1,
            'price' => 200,
            'weight' => 1,
            'options' => [
                'product_id' => $bundleParent->id,
                'code' => $bundleParent->product_code,
                'unit_price' => 200,
                'sub_total' => 200,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => null,
                'bundle_items' => [
                    [
                        'bundle_id' => $bundle->id,
                        'bundle_item_id' => 1,
                        'product_id' => $bundleComponent->id,
                        'name' => $bundleComponent->product_name,
                        'quantity' => 2,
                        'price' => 50,
                        'sub_total' => 100
                    ]
                ]
            ]
        ]);

        $sale = $saleService->createSale([
            'customer_id' => $customer->id,
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'payment_term_id' => $paymentTerm->id,
            'setting_id' => session('setting_id'),
        ], Cart::instance('sale')->content());

        $this->assertEquals(1, $sale->saleDetails()->count());
        $bundleComponent->refresh();
        $this->assertEquals(3, $bundleComponent->product_quantity);

        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => 'bundle_2',
            'name' => $bundleParent->product_name,
            'qty' => 2,
            'price' => 200,
            'weight' => 1,
            'options' => [
                'product_id' => $bundleParent->id,
                'code' => $bundleParent->product_code,
                'unit_price' => 200,
                'sub_total' => 400,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => null,
                'bundle_items' => [
                    [
                        'bundle_id' => $bundle->id,
                        'bundle_item_id' => 1,
                        'product_id' => $bundleComponent->id,
                        'name' => $bundleComponent->product_name,
                        'quantity' => 2,
                        'price' => 50,
                        'sub_total' => 100
                    ]
                ]
            ]
        ]);

        $updatedSale = $saleService->updateSale($sale, [
            'customer_id' => $customer->id,
            'date' => $sale->date,
        ], Cart::instance('sale')->content());

        $this->assertEquals(1, $updatedSale->saleDetails()->count());

        // Load sale detail's bundle items and verify exactly one bundle item persists
        $saleDetail = $updatedSale->saleDetails()->first();
        $bundleItems = $saleDetail->bundleItems()->get();

        $this->assertEquals(1, $bundleItems->count());
        $bundleItem = $bundleItems->first();
        $this->assertEquals($bundle->id, $bundleItem->bundle_id);
        $this->assertEquals($bundleComponent->id, $bundleItem->product_id);
        $this->assertEquals(2, $bundleItem->quantity);

        // Verify no stock or transaction changes
        $bundleParent->refresh();
        $this->assertEquals(100, $bundleParent->product_quantity);

        $bundleComponent->refresh();
        $this->assertEquals(3, $bundleComponent->product_quantity);

        // No inventory transactions should exist
        $parentTransactionCount = Transaction::where('product_id', $bundleParent->id)->count();
        $componentTransactionCount = Transaction::where('product_id', $bundleComponent->id)->count();
        $this->assertEquals(0, $parentTransactionCount);
        $this->assertEquals(0, $componentTransactionCount);
    }

}
