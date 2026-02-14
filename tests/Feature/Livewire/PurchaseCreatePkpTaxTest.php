<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Purchase\CreateForm;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchaseCreatePkpTaxTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;
    protected $pkpSetting;
    protected $product;
    protected $supplier;
    protected $codTerm;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->actingAs($user);

        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah', 'code' => 'IDR', 'symbol' => 'Rp', 'thousand_separator' => '.', 'decimal_separator' => ',', 'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Non-PKP Company', 'company_email' => 'test@example.com', 'company_phone' => '123', 'default_currency_id' => $currency->id, 'default_currency_position' => 'prefix', 'notification_email' => 'a@b.com', 'footer_text' => 'f', 'company_address' => 'a', 'is_pkp' => false,
        ]);

        $this->pkpSetting = Setting::create([
            'company_name' => 'PKP Company', 'company_email' => 'pkp@example.com', 'company_phone' => '456', 'default_currency_id' => $currency->id, 'default_currency_position' => 'prefix', 'notification_email' => 'p@k.p', 'footer_text' => 'f', 'company_address' => 'a', 'is_pkp' => true,
        ]);

        $this->codTerm = PaymentTerm::create(['name' => 'Cash on Delivery', 'longevity' => 0]);

        $this->product = Product::create([
            'product_name' => 'Test Product', 'product_code' => 'TP001', 'product_quantity' => 10, 'setting_id' => $this->pkpSetting->id, 'product_cost' => 1000, 'product_price' => 1200, 'product_unit' => 'pcs',
        ]);

        $this->supplier = Supplier::factory()->create(['setting_id' => $this->pkpSetting->id, 'payment_term_id' => $this->codTerm->id]);
        
        Cart::instance('purchase')->destroy();
    }

    public function test_pkp_submit_fails_when_any_item_has_no_tax()
    {
        session(['setting_id' => $this->pkpSetting->id]);

        $component = Livewire::test(CreateForm::class, ['idempotencyToken' => 'token-1']);

        Cart::instance('purchase')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 1000,
            'weight' => 1,
            'options' => [
                'sub_total' => 1000,
                'sub_total_before_tax' => 1000,
                'code' => $this->product->product_code,
                'product_tax' => null, // NO TAX
                'unit_price' => 1000,
                'product_discount_type' => 'fixed',
                'product_discount' => 0,
            ],
        ]);

        $component->set('supplier_id', $this->supplier->id)
            ->set('payment_term', $this->codTerm->id)
            ->call('submit')
            ->assertHasErrors(['cart']);
            
        $this->assertDatabaseCount('purchases', 0);
    }

    public function test_pkp_submit_succeeds_when_all_items_have_tax()
    {
        session(['setting_id' => $this->pkpSetting->id]);
        
        $tax = \Modules\Setting\Entities\Tax::create(['name' => 'PPN', 'value' => 11]);

        $component = Livewire::test(CreateForm::class, ['idempotencyToken' => 'token-2']);

        Cart::instance('purchase')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 1110,
            'weight' => 1,
            'options' => [
                'sub_total' => 1110,
                'sub_total_before_tax' => 1000,
                'code' => $this->product->product_code,
                'product_tax' => $tax->id,
                'unit_price' => 1000,
                'product_discount_type' => 'fixed',
                'product_discount' => 0,
            ],
        ]);

        $component->set('supplier_id', $this->supplier->id)
            ->set('payment_term', $this->codTerm->id)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('purchases.index'));
            
        $this->assertDatabaseCount('purchases', 1);
    }

    public function test_non_pkp_submit_succeeds_without_tax()
    {
        session(['setting_id' => $this->setting->id]);

        $component = Livewire::test(CreateForm::class, ['idempotencyToken' => 'token-3']);

        Cart::instance('purchase')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 1000,
            'weight' => 1,
            'options' => [
                'sub_total' => 1000,
                'sub_total_before_tax' => 1000,
                'code' => $this->product->product_code,
                'product_tax' => null, // NO TAX but it's OK for non-PKP
                'unit_price' => 1000,
                'product_discount_type' => 'fixed',
                'product_discount' => 0,
            ],
        ]);

        $component->set('supplier_id', $this->supplier->id)
            ->set('payment_term', $this->codTerm->id)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('purchases.index'));
            
        $this->assertDatabaseCount('purchases', 1);
    }
}
