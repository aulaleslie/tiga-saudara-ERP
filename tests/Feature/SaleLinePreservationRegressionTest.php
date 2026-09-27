<?php

namespace Tests\Feature;

use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductBundleItem;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Services\SaleService;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SaleLinePreservationRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->actingAs($user);
        Gate::before(fn() => true);

        $setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@test.com',
            'company_phone' => '123456789',
            'notification_email' => 'test@test.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => true,
        ]);

        Session::put('setting_id', $setting->id);
    }

    /** @test */
    public function creates_sale_with_duplicate_non_bundle_rows()
    {
        $product = Product::create([
            'setting_id' => 1,
            'product_name' => 'Test Product',
            'product_code' => 'P001',
            'product_quantity' => 10,
            'product_price' => 100,
            'product_unit' => 'pcs',
            'product_cost' => 0,
        ]);
        $customer = Customer::factory()->create();

        Cart::instance('sale')->destroy();
        $this->addCartRow($product, 2, 100);
        $this->addCartRow($product, 3, 100);

        $saleService = app(SaleService::class);
        $sale = $saleService->createSale([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'customer_id' => $customer->id,
            'setting_id' => 1,
        ], Cart::instance('sale')->content());

        $this->assertEquals(2, $sale->saleDetails()->count());
        $details = $sale->saleDetails()->get();
        $this->assertEquals(2, $details[0]->quantity);
        $this->assertEquals(3, $details[1]->quantity);
        $this->assertEquals(500, (float)$sale->total_amount);
    }

    /** @test */
    public function updates_sale_preserving_duplicate_rows()
    {
        $product = Product::create([
            'setting_id' => 1,
            'product_name' => 'Test Product',
            'product_code' => 'P001',
            'product_quantity' => 10,
            'product_price' => 100,
            'product_unit' => 'pcs',
            'product_cost' => 0,
        ]);
        $customer = Customer::factory()->create();

        $saleService = app(SaleService::class);
        
        // Initial purchase
        Cart::instance('sale')->destroy();
        $this->addCartRow($product, 1, 100);
        $sale = $saleService->createSale([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'customer_id' => $customer->id,
            'setting_id' => 1,
        ], Cart::instance('sale')->content());

        $this->assertEquals(1, $sale->saleDetails()->count());

        // Update to 2 rows
        Cart::instance('sale')->destroy();
        $this->addCartRow($product, 2, 100);
        $this->addCartRow($product, 3, 100);

        $saleService->updateSale($sale, [
            'customer_id' => $customer->id,
            'date' => $sale->date,
        ], Cart::instance('sale')->content());

        $sale->load('saleDetails');
        $this->assertEquals(2, $sale->saleDetails->count());
        $this->assertEquals(500, (float)$sale->total_amount);
    }

    /** @test */
    public function preserves_duplicate_bundle_parents_and_child_ownership()
    {
        $parent = Product::create([
            'setting_id' => 1,
            'product_name' => 'Parent Product',
            'product_code' => 'PARENT001',
            'product_quantity' => 10,
            'product_price' => 1000,
            'product_unit' => 'pcs',
            'product_cost' => 0,
        ]);
        $child = Product::create([
            'setting_id' => 1,
            'product_name' => 'Child Product',
            'product_code' => 'CHILD001',
            'product_quantity' => 100,
            'product_price' => 0,
            'product_unit' => 'pcs',
            'product_cost' => 0,
        ]);
        $customer = Customer::factory()->create();

        $bundle = ProductBundle::create(['parent_product_id' => $parent->id, 'name' => 'B1', 'price' => 50, 'setting_id' => 1]);
        ProductBundleItem::create(['bundle_id' => $bundle->id, 'product_id' => $child->id, 'quantity' => 1]);

        Cart::instance('sale')->destroy();
        $this->addBundleCartRow($parent, $bundle, 1, 1000); // 1000 + 50 = 1050
        $this->addBundleCartRow($parent, $bundle, 1, 1000); // 1000 + 50 = 1050

        $saleService = app(SaleService::class);
        $sale = $saleService->createSale([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'customer_id' => $customer->id,
            'setting_id' => 1,
        ], Cart::instance('sale')->content());

        $this->assertEquals(2, $sale->saleDetails()->count());
        $details = $sale->saleDetails()->with('bundleItems')->get();
        
        $this->assertEquals(1, $details[0]->bundleItems->count());
        $this->assertEquals(1, $details[1]->bundleItems->count());
        
        $this->assertNotEquals($details[0]->bundleItems->first()->id, $details[1]->bundleItems->first()->id);
        $this->assertEquals(2100, (float)$sale->total_amount);
    }

    private function addCartRow($product, $qty, $price)
    {
        Cart::instance('sale')->add([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'name' => $product->product_name,
            'qty' => $qty,
            'price' => $price,
            'weight' => 1,
            'options' => [
                'product_id' => $product->id,
                'sub_total' => $qty * $price,
                'sub_total_before_tax' => $qty * $price,
                'code' => $product->product_code,
            ]
        ]);
    }

    private function addBundleCartRow($product, $bundle, $qty, $price)
    {
        $bundleTotal = $bundle->price * $qty;
        $total = ($qty * $price) + $bundleTotal;
        
        Cart::instance('sale')->add([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'name' => $product->product_name,
            'qty' => $qty,
            'price' => $price,
            'weight' => 1,
            'options' => [
                'product_id' => $product->id,
                'sub_total' => $total,
                'sub_total_before_tax' => $total,
                'code' => $product->product_code,
                'bundle_price' => $bundleTotal,
                'bundle_items' => [
                    [
                        'bundle_id' => $bundle->id,
                        'bundle_item_id' => $bundle->items->first()->id,
                        'product_id' => $bundle->items->first()->product_id,
                        'name' => 'Child',
                        'quantity' => 1 * $qty,
                        'price' => 0,
                    ]
                ]
            ]
        ]);
    }
}
