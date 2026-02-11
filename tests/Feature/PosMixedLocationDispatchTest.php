<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Support\PosLocationResolver;
use App\Models\PosSession;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Sale;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Setting\Entities\Unit;
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

        Gate::before(fn () => true);
        $this->withoutMiddleware(CheckUserRoleForSetting::class);

        $user = User::factory()->create();
        $this->actingAs($user);

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->settingA = Setting::create([
            'company_name' => 'Setting A',
            'company_email' => 'setting-a@example.com',
            'company_phone' => '0800000000',
            'site_logo' => null,
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'left',
            'notification_email' => 'notify-a@example.com',
            'footer_text' => 'Footer A',
            'company_address' => 'Street A',
        ]);

        $this->settingB = Setting::create([
            'company_name' => 'Setting B',
            'company_email' => 'setting-b@example.com',
            'company_phone' => '0800000001',
            'site_logo' => null,
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'left',
            'notification_email' => 'notify-b@example.com',
            'footer_text' => 'Footer B',
            'company_address' => 'Street B',
        ]);

        $this->locationA = Location::create([
            'setting_id' => $this->settingA->id,
            'name' => 'Location A',
        ]);

        $this->locationB = Location::create([
            'setting_id' => $this->settingB->id,
            'name' => 'Location B',
        ]);

        SettingSaleLocation::updateOrCreate(
            ['location_id' => $this->locationA->id],
            ['setting_id' => $this->settingA->id, 'position' => 1]
        );

        SettingSaleLocation::updateOrCreate(
            ['location_id' => $this->locationB->id],
            ['setting_id' => $this->settingA->id, 'position' => 2]
        );

        $unit = Unit::create([
            'name' => 'PCS',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        $category = Category::create([
            'category_code' => 'CAT-01',
            'category_name' => 'Category',
            'created_by' => $user->id,
            'setting_id' => $this->settingA->id,
        ]);

        $this->product = Product::create([
            'setting_id' => $this->settingA->id,
            'category_id' => $category->id,
            'product_quantity' => 0,
            'stock_managed' => true,
            'serial_number_required' => false,
            'product_name' => 'Sample Product',
            'product_code' => 'PRD-01',
            'product_cost' => 5.00,
            'product_price' => 10.00,
            'product_stock_alert' => 0,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
        ]);

        $chartOfAccountId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'Kas',
            'account_number' => '1000',
            'category' => 'Kas & Bank',
            'parent_account_id' => null,
            'tax_id' => null,
            'description' => null,
            'setting_id' => $this->settingA->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->cashMethod = PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $chartOfAccountId,
            'is_cash' => true,
            'is_available_in_pos' => true,
        ]);

        $this->customer = Customer::factory()->create([
            'setting_id' => $this->settingA->id,
        ]);

        PosSession::create([
            'user_id' => $user->id,
            'setting_id' => $this->settingA->id,
            'location_id' => $this->locationA->id,
            'device_name' => 'TEST DEVICE',
            'cash_float' => 0,
            'expected_cash' => 0,
            'status' => PosSession::STATUS_ACTIVE,
            'started_at' => now(),
        ]);

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

        $this->assertSame($this->settingB->id, (int) $this->locationB->fresh()->setting_id);
        $this->assertSame(
            $this->settingA->id,
            (int) SettingSaleLocation::where('location_id', $this->locationB->id)->value('setting_id')
        );

        PosLocationResolver::setActiveAssignment(
            SettingSaleLocation::where('setting_id', $this->settingA->id)->value('id')
        );

        Cart::instance('sale')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 2,
            'price' => 10,
            'weight' => 1,
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
            'total_amount' => 20,
            'paid_amount' => 20,
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
        $this->assertEquals(
            collect([$this->settingA->id, $this->settingB->id])->sort()->values()->all(),
            $sales->pluck('setting_id')->sort()->values()->all()
        );

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
