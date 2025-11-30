<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Http\Middleware\EnsureActivePosSession;
use App\Livewire\Pos\Checkout;
use App\Models\PosSession;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Currency;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class SaleListShowsPosSalesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        $this->withoutMiddleware([
            CheckUserRoleForSetting::class,
            EnsureActivePosSession::class,
        ]);

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

        $setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'company@example.com',
            'company_phone' => '0800000000',
            'site_logo' => null,
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'left',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Street 1',
            'sale_prefix_document' => 'PSL',
        ]);

        Session::put('setting_id', $setting->id);

        $location = Location::create([
            'setting_id' => $setting->id,
            'name' => 'POS Store',
        ]);

        SettingSaleLocation::updateOrCreate(
            ['location_id' => $location->id],
            ['setting_id' => $setting->id, 'is_pos' => true, 'position' => 1]
        );

        PosSession::create([
            'user_id' => $user->id,
            'location_id' => $location->id,
            'device_name' => 'TEST DEVICE',
            'cash_float' => 0,
            'expected_cash' => 0,
            'status' => PosSession::STATUS_ACTIVE,
            'started_at' => now(),
        ]);

        $chartOfAccount = ChartOfAccount::create([
            'name' => 'Kas',
            'account_number' => '1000',
            'category' => 'Kas & Bank',
            'parent_account_id' => null,
            'tax_id' => null,
            'description' => null,
            'setting_id' => $setting->id,
        ]);

        PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $chartOfAccount->id,
            'is_cash' => true,
            'is_available_in_pos' => true,
        ]);

        $unit = Unit::create([
            'name' => 'PCS',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        $category = Category::create([
            'category_code' => 'CAT-01',
            'category_name' => 'Category',
        ]);

        Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'product_name' => 'Sample Product',
            'product_code' => 'PRD-01',
            'product_barcode_symbology' => null,
            'product_quantity' => 100,
            'product_cost' => 5.00,
            'product_price' => 10.00,
            'product_unit' => 'PCS',
            'product_stock_alert' => 5,
            'product_order_tax' => 0,
            'product_tax_type' => 0,
            'stock_managed' => true,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'sale_price' => 10.00,
            'tier_1_price' => 10.00,
            'tier_2_price' => 10.00,
        ]);

        Customer::factory()->create([
            'setting_id' => $setting->id,
        ]);

        Cart::instance('sale')->destroy();
    }

    protected function tearDown(): void
    {
        Cart::instance('sale')->destroy();

        parent::tearDown();
    }

    public function test_pos_sale_is_included_in_sales_datatable(): void
    {
        $customer = Customer::firstOrFail();
        $product = Product::firstOrFail();
        $paymentMethod = PaymentMethod::firstOrFail();

        $component = Livewire::test(Checkout::class, [
            'cartInstance' => 'sale',
            'customers' => Customer::all(),
        ]);

        $component->call('addProduct', $product->fresh()->toArray());

        $cart = Cart::instance('sale');
        $total = (float) $cart->total();

        $response = $this->post(route('app.pos.store'), [
            'customer_id' => $customer->id,
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'total_amount' => $total,
            'paid_amount' => $total,
            'payments' => [
                ['method_id' => $paymentMethod->id, 'amount' => $total],
            ],
        ]);

        $response->assertRedirect(route('app.pos.index'));

        $sale = \Modules\Sale\Entities\Sale::with('posReceipt')->latest('id')->first();
        $receiptNumber = $sale?->posReceipt?->receipt_number;

        $datatableResponse = $this->get(route('sales.index'), [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);

        $datatableResponse->assertOk();

        $rows = collect($datatableResponse->json('data'));

        $this->assertTrue($rows->pluck('reference')->contains($sale->reference));
        $this->assertTrue($rows->pluck('pos_receipt_number')->contains($receiptNumber));
        $this->assertTrue($rows->pluck('pos_session_id')->contains($sale->pos_session_id));
    }
}
