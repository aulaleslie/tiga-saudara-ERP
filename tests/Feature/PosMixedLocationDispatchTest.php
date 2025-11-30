<?php

namespace Tests\Feature;

use App\Support\PosLocationResolver;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Tests\TestCase;

class PosMixedLocationDispatchTest extends TestCase
{
    use RefreshDatabase;

    private Setting $settingA;
    private Setting $settingB;
    private Location $locationA;
    private Location $locationB;
    private Product $product;
    private PaymentMethod $cashMethod;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settingA = Setting::factory()->create();
        $this->settingB = Setting::factory()->create();

        $this->locationA = Location::factory()->create(['setting_id' => $this->settingA->id]);
        $this->locationB = Location::factory()->create(['setting_id' => $this->settingB->id]);

        SettingSaleLocation::create([
            'setting_id' => $this->settingA->id,
            'location_id' => $this->locationA->id,
            'is_pos' => true,
            'position' => 1,
        ]);

        SettingSaleLocation::create([
            'setting_id' => $this->settingB->id,
            'location_id' => $this->locationB->id,
            'is_pos' => true,
            'position' => 1,
        ]);

        $this->product = Product::factory()->create([
            'setting_id' => $this->settingA->id,
            'product_quantity' => 0,
            'stock_managed' => true,
            'serial_number_required' => false,
        ]);

        $this->cashMethod = PaymentMethod::factory()->create([
            'is_available_in_pos' => true,
        ]);

        $this->customer = Customer::factory()->create();

        Cart::instance('sale')->destroy();
    }

    protected function tearDown(): void
    {
        Cart::instance('sale')->destroy();
        parent::tearDown();
    }

    public function test_mixed_setting_allocations_split_sales_and_create_dispatches(): void
    {
        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->locationA->id,
            'quantity' => 1,
            'quantity_non_tax' => 1,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
        ]);

        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->locationB->id,
            'quantity' => 1,
            'quantity_non_tax' => 1,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
        ]);

        session([
            'setting_id' => $this->settingA->id,
            'currency_position' => 'left',
        ]);

        PosLocationResolver::setActiveAssignment(
            SettingSaleLocation::where('setting_id', $this->settingA->id)->value('id')
        );

        Cart::instance('sale')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 2,
            'price' => 10,
            'options' => [
                'product_id' => $this->product->id,
                'pos_location_allocations' => [
                    ['location_id' => $this->locationA->id, 'allocated_non_tax' => 1, 'allocated_tax' => 0],
                    ['location_id' => $this->locationB->id, 'allocated_non_tax' => 1, 'allocated_tax' => 0],
                ],
            ],
        ]);

        $response = $this->post(route('app.pos.store'), [
            'customer_id' => $this->customer->id,
            'payments' => [
                ['method_id' => $this->cashMethod->id, 'amount' => 20],
            ],
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
        ]);

        $response->assertRedirect(route('app.pos.index'));

        $sales = Sale::query()->with('saleDispatches.details')->latest('id')->take(2)->get();

        $this->assertCount(2, $sales);
        $this->assertTrue($sales->pluck('setting_id')->sort()->values()->equals(collect([$this->settingA->id, $this->settingB->id])->sort()->values()));

        $saleA = $sales->firstWhere('setting_id', $this->settingA->id);
        $saleB = $sales->firstWhere('setting_id', $this->settingB->id);

        $this->assertEquals(1, $saleA?->saleDetails()->sum('quantity'));
        $this->assertEquals(1, $saleB?->saleDetails()->sum('quantity'));

        $this->assertSame(0, ProductStock::where('location_id', $this->locationA->id)->value('quantity_non_tax'));
        $this->assertSame(0, ProductStock::where('location_id', $this->locationB->id)->value('quantity_non_tax'));

        $this->assertSame(1, $saleA?->saleDispatches->first()?->details->sum('dispatched_quantity'));
        $this->assertSame(1, $saleB?->saleDispatches->first()?->details->sum('dispatched_quantity'));
    }
}

