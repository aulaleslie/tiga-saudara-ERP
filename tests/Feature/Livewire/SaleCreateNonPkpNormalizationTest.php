<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Sale\CreateForm;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

class SaleCreateNonPkpNormalizationTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected PaymentTerm $paymentTerm;
    protected Customer $customer;
    protected Product $product;
    protected Tax $tax;

    protected function setUp(): void
    {
        parent::setUp();

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
            'company_name' => 'Non PKP Sale',
            'company_email' => 'nonpkp-sale@example.com',
            'company_phone' => '123456',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => false,
        ]);
        session(['setting_id' => $this->setting->id]);

        $this->paymentTerm = PaymentTerm::query()->firstOrCreate(
            ['name' => 'Cash on Delivery'],
            ['longevity' => 0]
        );

        $this->customer = Customer::factory()->create([
            'setting_id' => $this->setting->id,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        $this->product = Product::create([
            'product_name' => 'Hidden Tax Sale Product',
            'product_code' => 'SALE-HT-001',
            'product_quantity' => 50,
            'setting_id' => $this->setting->id,
            'product_cost' => 1000,
            'product_price' => 1110,
            'product_unit' => 'pcs',
        ]);

        $this->tax = Tax::create([
            'name' => 'PPN 11',
            'value' => 11,
        ]);

        Cart::instance('sale')->destroy();
    }

    protected function tearDown(): void
    {
        Cart::instance('sale')->destroy();

        parent::tearDown();
    }

    public function test_non_pkp_create_submit_normalizes_hidden_tax_state(): void
    {
        Cart::instance('sale')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 1110,
            'weight' => 1,
            'options' => [
                'sub_total' => 1110,
                'sub_total_before_tax' => 1000,
                'product_tax_amount' => 110,
                'code' => $this->product->product_code,
                'product_tax' => $this->tax->id,
                'unit_price' => 1110,
                'product_id' => $this->product->id,
                'product_discount_type' => 'fixed',
                'product_discount' => 0,
            ],
        ]);

        Livewire::test(CreateForm::class, ['idempotencyToken' => 'sale-non-pkp-hidden-tax'])
            ->set('customerId', $this->customer->id)
            ->set('paymentTermId', $this->paymentTerm->id)
            ->set('date', now()->toDateString())
            ->set('dueDate', now()->toDateString())
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('sales.index'));

        $sale = Sale::query()->latest('id')->firstOrFail();
        $detail = SaleDetails::query()->where('sale_id', $sale->id)->firstOrFail();

        $this->assertSame(0.0, (float) $sale->tax_amount);
        $this->assertSame(1000.0, (float) $sale->total_amount);
        $this->assertSame(1000.0, (float) $sale->due_amount);
        $this->assertNull($detail->tax_id);
        $this->assertSame(0.0, (float) $detail->product_tax_amount);
        $this->assertSame(1000.0, (float) $detail->sub_total);
    }
}
