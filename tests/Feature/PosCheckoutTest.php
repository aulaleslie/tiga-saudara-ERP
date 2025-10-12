<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Livewire\Pos\Checkout;
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
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
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

        $this->chartOfAccount = ChartOfAccount::create([
            'name' => 'Kas',
            'account_number' => '1000',
            'category' => 'Kas & Bank',
            'parent_account_id' => null,
            'tax_id' => null,
            'description' => null,
            'setting_id' => $this->setting->id,
        ]);

        $this->customer = Customer::factory()->create([
            'setting_id' => $this->setting->id,
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
        $component->assertSet('selected_payment_method_id', $posMethod->id);
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

        $component = Livewire::test(Checkout::class, [
            'cartInstance' => 'sale',
            'customers' => Customer::all(),
        ]);

        $component
            ->set('selected_payment_method_id', $cardMethod->id)
            ->set('total_amount', 100)
            ->set('paid_amount', 150)
            ->assertSet('changeDue', 0.0)
            ->assertSet('overPaidWithNonCash', true)
            ->assertSee('Overpayment of');

        $component
            ->set('selected_payment_method_id', $cashMethod->id)
            ->set('total_amount', 100)
            ->set('paid_amount', 150)
            ->assertSet('changeDue', 50.0)
            ->assertSet('overPaidWithNonCash', false);
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
                'sub_total_before_tax' => 100,
                'bundle_items' => [],
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
            'payment_method_id' => $nonCashMethod->id,
            'note' => '',
        ]);

        $response->assertSessionHasErrors(['paid_amount']);
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_pos_store_rejects_payment_methods_not_available_in_pos(): void
    {
        $nonPosMethod = PaymentMethod::create([
            'name' => 'Bank Transfer',
            'coa_id' => $this->chartOfAccount->id,
            'is_cash' => false,
            'is_available_in_pos' => false,
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
                'sub_total_before_tax' => 100,
                'bundle_items' => [],
                'stock' => 100,
            ],
        ]);

        $response = $this->post(route('app.pos.store'), [
            'customer_id' => $this->customer->id,
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100,
            'paid_amount' => 100,
            'payment_method_id' => $nonPosMethod->id,
            'note' => '',
        ]);

        $response->assertSessionHasErrors(['payment_method_id']);
    }
}
