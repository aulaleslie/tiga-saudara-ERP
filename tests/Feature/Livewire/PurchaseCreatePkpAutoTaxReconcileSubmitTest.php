<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Purchase\CreateForm;
use App\Livewire\Purchase\ProductCart;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

class PurchaseCreatePkpAutoTaxReconcileSubmitTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected Product $product;
    protected Supplier $supplier;
    protected PaymentTerm $codTerm;
    protected Tax $defaultTax;

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
            'company_name' => 'PKP Company',
            'company_email' => 'pkp@example.com',
            'company_phone' => '456',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'p@k.p',
            'footer_text' => 'f',
            'company_address' => 'a',
            'is_pkp' => true,
        ]);
        session(['setting_id' => $this->setting->id]);

        $this->codTerm = PaymentTerm::query()->firstOrCreate(
            ['name' => 'Cash on Delivery'],
            ['longevity' => 0]
        );

        $this->defaultTax = Tax::create([
            'name' => 'PPN 11',
            'value' => 11,
            'is_default' => true,
        ]);

        $this->product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TP001',
            'product_quantity' => 10,
            'setting_id' => $this->setting->id,
            'product_cost' => 1000,
            'product_price' => 1200,
            'product_unit' => 'pcs',
        ]);

        $this->supplier = Supplier::factory()->create([
            'setting_id' => $this->setting->id,
            'payment_term_id' => $this->codTerm->id,
        ]);

        Cart::instance('purchase')->destroy();
    }

    public function test_pkp_create_submit_succeeds_after_product_cart_reconciles_missing_default_tax(): void
    {
        $createForm = Livewire::test(CreateForm::class, ['idempotencyToken' => 'pkp-auto-reconcile-token']);

        Cart::instance('purchase')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 1000,
            'weight' => 1,
            'options' => [
                'sub_total' => 1000,
                'sub_total_before_tax' => 1000,
                'product_tax_amount' => 0,
                'code' => $this->product->product_code,
                'stock' => $this->product->product_quantity,
                'unit' => $this->product->product_unit,
                'product_tax' => null, // stale/missing tax in cart row
                'unit_price' => 1000,
                'product_discount_type' => 'fixed',
                'product_discount' => 0,
                'product_discount_input' => 0,
                'last_purchase_price' => 1000,
                'average_purchase_price' => 1000,
            ],
        ]);

        Livewire::test(ProductCart::class, ['cartInstance' => 'purchase']);

        $cartItem = Cart::instance('purchase')->content()->firstWhere('id', $this->product->id);
        $this->assertNotNull($cartItem);
        $this->assertSame($this->defaultTax->id, (int) $cartItem->options->product_tax);

        $createForm->set('supplier_id', $this->supplier->id)
            ->set('payment_term', $this->codTerm->id)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('purchases.index'));

        $this->assertDatabaseCount('purchases', 1);
        $this->assertDatabaseHas('purchase_details', [
            'product_id' => $this->product->id,
            'tax_id' => $this->defaultTax->id,
        ]);
    }

    public function test_pkp_create_submit_fails_clearly_when_no_product_tax_and_no_default_tax_exist(): void
    {
        $this->defaultTax->update(['is_default' => false]);

        $latestTax = Tax::create([
            'name' => 'PPN 12',
            'value' => 12,
            'is_default' => false,
        ]);

        $createForm = Livewire::test(CreateForm::class, ['idempotencyToken' => 'pkp-latest-tax-reconcile-token']);

        Cart::instance('purchase')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 1000,
            'weight' => 1,
            'options' => [
                'sub_total' => 1000,
                'sub_total_before_tax' => 1000,
                'product_tax_amount' => 0,
                'code' => $this->product->product_code,
                'stock' => $this->product->product_quantity,
                'unit' => $this->product->product_unit,
                'product_tax' => null,
                'unit_price' => 1000,
                'product_discount_type' => 'fixed',
                'product_discount' => 0,
                'product_discount_input' => 0,
                'last_purchase_price' => 1000,
                'average_purchase_price' => 1000,
            ],
        ]);

        Livewire::test(ProductCart::class, ['cartInstance' => 'purchase']);

        $cartItem = Cart::instance('purchase')->content()->firstWhere('id', $this->product->id);
        $this->assertNotNull($cartItem);
        $this->assertNotNull($latestTax);
        $this->assertNull($cartItem->options->product_tax);

        $createForm->set('supplier_id', $this->supplier->id)
            ->set('payment_term', $this->codTerm->id)
            ->call('submit')
            ->assertHasErrors(['cart']);

        $this->assertDatabaseCount('purchases', 0);
    }
}
