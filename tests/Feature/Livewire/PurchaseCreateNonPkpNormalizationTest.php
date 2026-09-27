<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Purchase\CreateForm;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

class PurchaseCreateNonPkpNormalizationTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected PaymentTerm $paymentTerm;
    protected Supplier $supplier;
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
            'company_name' => 'Non PKP',
            'company_email' => 'nonpkp@example.com',
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

        $this->supplier = Supplier::factory()->create([
            'setting_id' => $this->setting->id,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        $this->product = Product::create([
            'product_name' => 'Hidden Tax Product',
            'product_code' => 'HT-001',
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

        Cart::instance('purchase')->destroy();
    }

    protected function tearDown(): void
    {
        Cart::instance('purchase')->destroy();

        parent::tearDown();
    }

    public function test_non_pkp_create_submit_normalizes_hidden_tax_state(): void
    {
        $component = Livewire::test(CreateForm::class, ['idempotencyToken' => 'non-pkp-hidden-tax']);

        Cart::instance('purchase')->add([
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
                'product_discount_type' => 'fixed',
                'product_discount' => 0,
            ],
        ]);

        $component
            ->set('supplier_id', $this->supplier->id)
            ->set('payment_term', $this->paymentTerm->id)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('purchases.index'));

        $purchase = Purchase::query()->latest('id')->firstOrFail();
        $detail = PurchaseDetail::query()->where('purchase_id', $purchase->id)->firstOrFail();

        $this->assertSame(0.0, (float) $purchase->tax_amount);
        $this->assertSame(1000.0, (float) $purchase->total_amount);
        $this->assertSame(1000.0, (float) $purchase->due_amount);
        $this->assertNull($detail->tax_id);
        $this->assertSame(0.0, (float) $detail->product_tax_amount);
        $this->assertSame(1000.0, (float) $detail->sub_total);
    }
}
