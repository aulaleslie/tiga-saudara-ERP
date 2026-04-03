<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Sale\EditForm;
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

class SaleEditNonPkpNormalizationTest extends TestCase
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
            'company_name' => 'Edit Non PKP Sale',
            'company_email' => 'edit-nonpkp-sale@example.com',
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
            'product_name' => 'Restored Tax Sale Product',
            'product_code' => 'SALE-RT-001',
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

    public function test_non_pkp_edit_clears_hidden_tax_state_and_normalizes_submit(): void
    {
        $sale = $this->createTaxBearingSale();

        $component = Livewire::test(EditForm::class, ['sale' => $sale]);

        $cartItem = Cart::instance('sale')->content()->first();

        $this->assertNotNull($cartItem);
        $this->assertNull($cartItem->options->product_tax);
        $this->assertSame(1000.0, (float) $cartItem->options->sub_total);
        $this->assertSame(1000.0, (float) $cartItem->options->sub_total_before_tax);
        $this->assertSame(0.0, (float) $cartItem->options->product_tax_amount);

        $component
            ->call('update')
            ->assertHasNoErrors()
            ->assertRedirect(route('sales.index'));

        $sale->refresh();
        $detail = SaleDetails::query()->where('sale_id', $sale->id)->firstOrFail();

        $this->assertSame(0.0, (float) $sale->tax_amount);
        $this->assertSame(1000.0, (float) $sale->total_amount);
        $this->assertSame(1000.0, (float) $sale->due_amount);
        $this->assertNull($sale->tax_ref_no);
        $this->assertNull($detail->tax_id);
        $this->assertSame(0.0, (float) $detail->product_tax_amount);
        $this->assertSame(1000.0, (float) $detail->sub_total);
    }

    private function createTaxBearingSale(): Sale
    {
        $sale = Sale::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'reference' => 'EDIT-SALE-001',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_ref_no' => 'FP-001',
            'tax_id' => null,
            'tax_percentage' => 0,
            'tax_amount' => 110,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1110,
            'paid_amount' => 0,
            'due_amount' => 1110,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => null,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 1110,
            'unit_price' => 1110,
            'sub_total' => 1110,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 110,
            'tax_id' => $this->tax->id,
        ]);

        return $sale;
    }
}
