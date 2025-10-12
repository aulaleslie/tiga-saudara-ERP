<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Livewire\Sale\CreateForm;
use App\Livewire\Sale\ProductCart;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Modules\People\Entities\Customer;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Currency;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

class SaleSharedMasterDataTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $primarySetting;
    protected Setting $secondarySetting;
    protected PaymentTerm $sharedTerm;
    protected PaymentMethod $sharedMethod;
    protected Tax $sharedTax;
    protected Customer $primaryCustomer;
    protected Customer $secondaryCustomer;
    protected Sale $sale;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        $this->withoutMiddleware(CheckUserRoleForSetting::class);

        Cart::instance('sale')->destroy();

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

        $this->primarySetting = Setting::create([
            'company_name' => 'Primary Co',
            'company_email' => 'primary@example.com',
            'company_phone' => '123456789',
            'site_logo' => null,
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'left',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address 1',
        ]);

        $this->secondarySetting = Setting::create([
            'company_name' => 'Secondary Co',
            'company_email' => 'secondary@example.com',
            'company_phone' => '987654321',
            'site_logo' => null,
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'left',
            'notification_email' => 'notify2@example.com',
            'footer_text' => 'Footer 2',
            'company_address' => 'Address 2',
        ]);

        Session::put('setting_id', $this->primarySetting->id);

        $chartOfAccount = ChartOfAccount::create([
            'name' => 'Kas',
            'account_number' => '1000',
            'category' => 'Kas & Bank',
            'parent_account_id' => null,
            'tax_id' => null,
            'description' => null,
            'setting_id' => $this->primarySetting->id,
        ]);

        $this->sharedMethod = PaymentMethod::create([
            'name' => 'Shared Cash',
            'coa_id' => $chartOfAccount->id,
            'is_cash' => true,
            'is_available_in_pos' => true,
        ]);

        $this->sharedTerm = PaymentTerm::create([
            'name' => 'Global Term',
            'longevity' => 7,
        ]);

        $this->sharedTax = Tax::create([
            'name' => 'VAT 10',
            'value' => 10,
        ]);

        $this->primaryCustomer = Customer::factory()->create([
            'setting_id' => $this->primarySetting->id,
            'payment_term_id' => $this->sharedTerm->id,
        ]);

        $this->secondaryCustomer = Customer::factory()->create([
            'setting_id' => $this->secondarySetting->id,
            'payment_term_id' => $this->sharedTerm->id,
        ]);

        $this->sale = Sale::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'customer_id' => $this->primaryCustomer->id,
            'customer_name' => $this->primaryCustomer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => '',
            'note' => null,
            'payment_term_id' => $this->sharedTerm->id,
            'tax_id' => null,
            'setting_id' => $this->primarySetting->id,
            'is_tax_included' => false,
        ]);
    }

    public function test_sale_create_view_lists_shared_master_data(): void
    {
        $response = $this->get(route('sales.create'));

        $response->assertOk();
        $response->assertViewHas('paymentTerms', fn ($terms) => $terms->contains('id', $this->sharedTerm->id));
        $response->assertViewHas('customers', fn ($customers) => $customers->contains('id', $this->secondaryCustomer->id));
    }

    public function test_sale_payments_use_shared_payment_methods(): void
    {
        $response = $this->get(route('sale-payments.create', $this->sale->id));

        $response->assertOk();
        $response->assertViewHas('payment_methods', fn ($methods) => $methods->contains('id', $this->sharedMethod->id));
    }

    public function test_livewire_components_pull_shared_master_data(): void
    {
        Livewire::test(CreateForm::class)
            ->assertSet('paymentTerms', fn ($terms) => $terms->contains('id', $this->sharedTerm->id));

        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->assertSet('taxes', fn ($taxes) => $taxes->contains('id', $this->sharedTax->id));
    }
}
