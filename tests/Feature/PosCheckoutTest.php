<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Livewire\Pos\Checkout;
use App\Models\PosSession;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Product\Entities\ProductUnitConversionPrice;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class PosCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Customer $customer;
    protected Product $product;
    protected ChartOfAccount $chartOfAccount;

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

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'company@example.com',
            'company_phone' => '0800000000',
            'site_logo' => null,
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'left',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Street 1',
        ]);

        Session::put('setting_id', $this->setting->id);

        $location = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'POS Location',
        ]);

        SettingSaleLocation::updateOrCreate(
            ['location_id' => $location->id],
            ['setting_id' => $this->setting->id, 'position' => 1]
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
            'setting_id' => $this->setting->id,
        ]);

        $this->product = Product::create([
            'setting_id' => $this->setting->id,
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

        $chartOfAccountId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'Kas',
            'account_number' => '1000',
            'category' => 'Kas & Bank',
            'parent_account_id' => null,
            'tax_id' => null,
            'description' => null,
            'setting_id' => $this->setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->chartOfAccount = ChartOfAccount::findOrFail($chartOfAccountId);

        $this->customer = Customer::factory()->create([
            'setting_id' => $this->setting->id,
        ]);

        PosSession::create([
            'user_id' => $user->id,
            'setting_id' => $this->setting->id,
            'location_id' => $location->id,
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

    public function test_checkout_component_filters_payment_methods_to_pos_enabled(): void
    {
        $posMethod = PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $this->chartOfAccount->id,
            'is_cash' => true,
            'is_available_in_pos' => true,
        ]);

        PaymentMethod::create([
            'name' => 'Wire Transfer',
            'coa_id' => $this->chartOfAccount->id,
            'is_cash' => false,
            'is_available_in_pos' => false,
        ]);

        $component = Livewire::test(Checkout::class, [
            'cartInstance' => 'sale',
            'customers' => Customer::all(),
        ]);

        $methods = collect($component->get('paymentMethods'));

        $this->assertSame([$posMethod->id], $methods->pluck('id')->map(fn ($id) => (int) $id)->all());

        $payments = collect($component->get('payments'));
        $this->assertCount(1, $payments);
        $this->assertSame($posMethod->id, (int) $payments->first()['method_id']);
        $component->assertDontSee('Wire Transfer');
    }

    public function test_checkout_component_enforces_cash_change_rules(): void
    {
        $cashMethod = PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $this->chartOfAccount->id,
            'is_cash' => true,
            'is_available_in_pos' => true,
        ]);

        $cardMethod = PaymentMethod::create([
            'name' => 'Card',
            'coa_id' => $this->chartOfAccount->id,
            'is_cash' => false,
            'is_available_in_pos' => true,
        ]);

        Cart::instance('sale')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 100,
            'weight' => 1,
            'options' => [
                'product_id' => $this->product->id,
                'code' => $this->product->product_code,
                'unit_price' => 100,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => 0,
                'sub_total' => 100,
                'stock' => 100,
            ],
        ]);

        $component = Livewire::test(Checkout::class, [
            'cartInstance' => 'sale',
            'customers' => Customer::all(),
        ]);

        $component
            ->set('payments.0.method_id', $cardMethod->id)
            ->set('total_amount', 100)
            ->set('payments.0.amount', 150)
            ->set('paid_amount', 150)
            ->assertSet('changeDue', 0.0)
            ->assertSet('overPaidWithNonCash', true)
            ->assertSee('Kelebihan pembayaran');

        $component
            ->set('payments.0.method_id', $cashMethod->id)
            ->set('total_amount', 100)
            ->set('payments.0.amount', 150)
            ->set('paid_amount', 150)
            ->assertSet('changeDue', 50.0)
            ->assertSet('overPaidWithNonCash', false);
    }

    public function test_checkout_component_detects_cash_overpayment_with_masked_values(): void
    {
        $cashMethod = PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $this->chartOfAccount->id,
            'is_cash' => true,
            'is_available_in_pos' => true,
        ]);

        Cart::instance('sale')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 55000,
            'weight' => 1,
            'options' => [
                'product_id' => $this->product->id,
                'code' => $this->product->product_code,
                'unit_price' => 55000,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => 0,
                'sub_total' => 55000,
                'stock' => 100,
            ],
        ]);

        $component = Livewire::test(Checkout::class, [
            'cartInstance' => 'sale',
            'customers' => Customer::all(),
        ]);

        $component
            ->set('payments.0.method_id', (string) $cashMethod->id)
            ->set('total_amount', '55000')
            ->set('payments.0.amount', '100.000')
            ->set('paid_amount', 100000)
            ->assertSet('hasCashPayment', true)
            ->assertSet('overPaidWithNonCash', false)
            ->assertSet('changeDue', 45000.0);
    }

    public function test_change_modal_displays_formatted_rupiah_when_change_positive(): void
    {
        $cashMethod = PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $this->chartOfAccount->id,
            'is_cash' => true,
            'is_available_in_pos' => true,
        ]);

        Cart::instance('sale')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 100000,
            'weight' => 1,
            'options' => [
                'product_id' => $this->product->id,
                'code' => $this->product->product_code,
                'unit_price' => 100000,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => 0,
                'sub_total' => 100000,
                'stock' => 100,
            ],
        ]);

        $component = Livewire::test(Checkout::class, [
            'cartInstance' => 'sale',
            'customers' => Customer::all(),
        ]);

        $component
            ->set('payments.0.method_id', $cashMethod->id)
            ->set('total_amount', 100000)
            ->set('payments.0.amount', 150000)
            ->set('paid_amount', 150000)
            ->call('openChangeModal')
            ->assertSet('changeModalHasPositiveChange', true)
            ->assertSee('KEMBALIAN RP. 50.000,00 . JANGAN LUPA UCAPKAN TERIMA KASIH!!');
    }

    public function test_change_modal_displays_thank_you_when_no_change_due(): void
    {
        $cashMethod = PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $this->chartOfAccount->id,
            'is_cash' => true,
            'is_available_in_pos' => true,
        ]);

        Cart::instance('sale')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 125000,
            'weight' => 1,
            'options' => [
                'product_id' => $this->product->id,
                'code' => $this->product->product_code,
                'unit_price' => 125000,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => 0,
                'sub_total' => 125000,
                'stock' => 100,
            ],
        ]);

        $component = Livewire::test(Checkout::class, [
            'cartInstance' => 'sale',
            'customers' => Customer::all(),
        ]);

        $component
            ->set('payments.0.method_id', $cashMethod->id)
            ->set('total_amount', 125000)
            ->set('payments.0.amount', 125000)
            ->call('openChangeModal')
            ->assertSet('changeModalHasPositiveChange', false)
            ->assertSee('JANGAN LUPA UCAPKAN TERIMA KASIH');
    }

    public function test_pos_store_rejects_overpayment_for_non_cash_methods(): void
    {
        $nonCashMethod = PaymentMethod::create([
            'name' => 'Card',
            'coa_id' => $this->chartOfAccount->id,
            'is_cash' => false,
            'is_available_in_pos' => true,
        ]);

        Cart::instance('sale')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 100,
            'weight' => 1,
            'options' => [
                'product_id' => $this->product->id,
                'code' => $this->product->product_code,
                'unit_price' => 100,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => 0,
                'sub_total' => 100,
                'stock' => 100,
            ],
        ]);

        $response = $this->post(route('app.pos.store'), [
            'customer_id' => $this->customer->id,
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100,
            'paid_amount' => 150,
            'payments' => [
                ['method_id' => $nonCashMethod->id, 'amount' => 150],
            ],
            'note' => '',
        ]);

        $response->assertSessionHasErrors(['payments.0.amount']);
    }

    public function test_pos_store_accepts_cash_and_card_payments(): void
    {
        $cashMethod = PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $this->chartOfAccount->id,
            'is_cash' => true,
            'is_available_in_pos' => true,
        ]);

        $cardMethod = PaymentMethod::create([
            'name' => 'Card',
            'coa_id' => $this->chartOfAccount->id,
            'is_cash' => false,
            'is_available_in_pos' => true,
        ]);

        Cart::instance('sale')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 100,
            'weight' => 1,
            'options' => [
                'product_id' => $this->product->id,
                'code' => $this->product->product_code,
                'unit_price' => 100,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => 0,
                'sub_total' => 100,
                'stock' => 100,
            ],
        ]);

        $response = $this->post(route('app.pos.store'), [
            'customer_id' => $this->customer->id,
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100,
            'paid_amount' => 110,
            'payments' => [
                ['method_id' => $cardMethod->id, 'amount' => 60],
                ['method_id' => $cashMethod->id, 'amount' => 50],
            ],
            'note' => '',
        ]);

        $response->assertRedirect(route('app.pos.index'));

        $this->assertDatabaseHas('sales', [
            'customer_id' => $this->customer->id,
            'total_amount' => 100,
            'paid_amount' => 100,
            'payment_method' => 'MULTIPLE',
            'payment_status' => 'PAID',
        ]);

        $this->assertDatabaseHas('sale_payments', [
            'payment_method_id' => $cardMethod->id,
            'amount' => 60,
        ]);

        $this->assertDatabaseHas('sale_payments', [
            'payment_method_id' => $cashMethod->id,
            'amount' => 40, // 100 - 60
        ]);
    }

    public function test_pos_store_handles_cash_overpayment_with_change(): void
    {
        $cashMethod = PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $this->chartOfAccount->id,
            'is_cash' => true,
            'is_available_in_pos' => true,
        ]);

        Cart::instance('sale')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 100,
            'weight' => 1,
            'options' => [
                'product_id' => $this->product->id,
                'code' => $this->product->product_code,
                'unit_price' => 100,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => 0,
                'sub_total' => 100,
                'stock' => 100,
            ],
        ]);

        $response = $this->post(route('app.pos.store'), [
            'customer_id' => $this->customer->id,
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100,
            'paid_amount' => 120,
            'payments' => [
                ['method_id' => $cashMethod->id, 'amount' => 120],
            ],
            'note' => '',
        ]);

        $response->assertRedirect(route('app.pos.index'));

        $this->assertDatabaseHas('sales', [
            'customer_id' => $this->customer->id,
            'total_amount' => 100,
            'paid_amount' => 100,
            'payment_method' => 'CASH',
            'payment_status' => 'PAID',
        ]);

        $this->assertDatabaseHas('sale_payments', [
            'payment_method_id' => $cashMethod->id,
            'amount' => 100,
        ]);
    }

    public function test_pos_store_accepts_sequential_non_cash_overpayments_as_exact(): void
    {
        $cardMethod = PaymentMethod::create([
            'name' => 'Card',
            'coa_id' => $this->chartOfAccount->id,
            'is_cash' => false,
            'is_available_in_pos' => true,
        ]);

        Cart::instance('sale')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 100,
            'weight' => 1,
            'options' => [
                'product_id' => $this->product->id,
                'code' => $this->product->product_code,
                'unit_price' => 100,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => 0,
                'sub_total' => 100,
                'stock' => 100,
            ],
        ]);

        // Sequential 50 + 50 = 100. Correct.
        $this->post(route('app.pos.store'), [
            'customer_id' => $this->customer->id,
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100,
            'paid_amount' => 100,
            'payments' => [
                ['method_id' => $cardMethod->id, 'amount' => 50],
                ['method_id' => $cardMethod->id, 'amount' => 50],
            ],
            'note' => '',
        ])->assertRedirect(route('app.pos.index'));

        $this->assertDatabaseHas('sales', [
            'customer_id' => $this->customer->id,
            'total_amount' => 100,
            'paid_amount' => 100,
            'payment_method' => 'CARD',
            'payment_status' => 'PAID',
        ]);
    }

    public function test_cascading_pricing_uses_setting_specific_conversion_prices(): void
    {
        $baseUnit = Unit::findOrFail($this->product->unit_id);
        $boxUnit = Unit::create(['name' => 'BOX', 'short_name' => 'BOX', 'operator' => '*', 'operation_value' => 12]);
        $packUnit = Unit::create(['name' => 'PACK', 'short_name' => 'PACK', 'operator' => '*', 'operation_value' => 6]);
        $baseUnitId = $this->product->unit_id;

        $boxConversion = ProductUnitConversion::create([
            'product_id' => $this->product->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $baseUnitId,
            'conversion_factor' => 12,
            'price' => 0,
        ]);

        ProductUnitConversionPrice::create([
            'product_unit_conversion_id' => $boxConversion->id,
            'setting_id' => $this->setting->id,
            'price' => 100,
        ]);

        ProductUnitConversion::create([
            'product_id' => $this->product->id,
            'unit_id' => $packUnit->id,
            'base_unit_id' => $baseUnitId,
            'conversion_factor' => 6,
            'price' => 0,
        ]);

        $component = Livewire::test(Checkout::class, [
            'cartInstance' => 'sale',
            'customers' => Customer::all(),
        ]);

        $result = $component->instance()->calculate($this->product->fresh(), 15);
        $context = $result['conversion_context'];

        $this->assertEquals(130.0, $result['sub_total']);
        $this->assertEquals(8.67, $result['unit_price']);
        $this->assertSame('1 BOX, 3 PCS', $context['breakdown']);

        $this->assertCount(2, $context['segments']);

        $firstSegment = $context['segments'][0];
        $this->assertSame('BOX', $firstSegment['unit_name']);
        $this->assertSame(1, $firstSegment['count']);
        $this->assertEquals(100.0, $firstSegment['sub_total']);

        $secondSegment = $context['segments'][1];
        $this->assertSame('PCS', $secondSegment['unit_name']);
        $this->assertSame(3, $secondSegment['count']);
        $this->assertEquals(30.0, $secondSegment['sub_total']);
        $this->assertEquals(10.0, $secondSegment['price']);

        $unitNames = collect($context['segments'])->pluck('unit_name')->all();
        $this->assertNotContains('PACK', $unitNames);
    }
}
