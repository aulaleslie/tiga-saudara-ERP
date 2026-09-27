<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Purchase\CreateForm;
use App\Livewire\Purchase\ProductCart;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Purchase\Entities\Purchase;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

class PurchaseCreatePkpTaxTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;
    protected $pkpSetting;
    protected $product;
    protected $secondProduct;
    protected $supplier;
    protected $nonPkpSupplier;
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

        $this->secondProduct = Product::create([
            'product_name' => 'Test Product Two', 'product_code' => 'TP002', 'product_quantity' => 10, 'setting_id' => $this->pkpSetting->id, 'product_cost' => 1000, 'product_price' => 1200, 'product_unit' => 'pcs',
        ]);

        $this->supplier = Supplier::factory()->create(['setting_id' => $this->pkpSetting->id, 'payment_term_id' => $this->codTerm->id]);
        $this->nonPkpSupplier = Supplier::factory()->create(['setting_id' => $this->setting->id, 'payment_term_id' => $this->codTerm->id]);
        
        Cart::instance('purchase')->destroy();
    }

    public function test_new_pkp_purchase_defaults_tax_included_and_persists_true_on_submit(): void
    {
        session(['setting_id' => $this->pkpSetting->id]);

        $tax = Tax::create(['name' => 'PPN', 'value' => 11, 'is_default' => true]);

        $component = Livewire::test(CreateForm::class, ['idempotencyToken' => 'token-pkp-default-true'])
            ->assertSet('isPkp', true)
            ->assertSet('is_tax_included', true);

        $this->seedPurchaseCartRow($this->product, $tax->id);

        $component
            ->set('supplier_id', $this->supplier->id)
            ->set('payment_term', $this->codTerm->id)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('purchases.index'));

        $purchase = Purchase::query()->latest('id')->first();

        $this->assertNotNull($purchase);
        $this->assertTrue((bool) $purchase->is_tax_included);
    }

    public function test_new_non_pkp_purchase_defaults_tax_included_false_and_hides_checkbox(): void
    {
        session(['setting_id' => $this->setting->id]);

        Livewire::test(CreateForm::class, ['idempotencyToken' => 'token-non-pkp-default-false'])
            ->assertSet('isPkp', false)
            ->assertSet('is_tax_included', false);

        Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->assertDontSee('Termasuk Pajak');
    }

    public function test_new_non_pkp_purchase_persists_false_on_submit(): void
    {
        session(['setting_id' => $this->setting->id]);

        $component = Livewire::test(CreateForm::class, ['idempotencyToken' => 'token-non-pkp-submit'])
            ->assertSet('is_tax_included', false);

        $this->seedPurchaseCartRow($this->product, null);

        $component
            ->set('supplier_id', $this->nonPkpSupplier->id)
            ->set('payment_term', $this->codTerm->id)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('purchases.index'));

        $purchase = Purchase::query()->latest('id')->first();

        $this->assertNotNull($purchase);
        $this->assertFalse((bool) $purchase->is_tax_included);
    }

    public function test_new_non_pkp_purchase_normalizes_hidden_tax_state_on_submit(): void
    {
        session(['setting_id' => $this->setting->id]);

        $tax = Tax::create(['name' => 'PPN Hidden', 'value' => 11, 'is_default' => true]);

        $component = Livewire::test(CreateForm::class, ['idempotencyToken' => 'token-non-pkp-hidden-tax']);

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
                'product_tax' => $tax->id,
                'unit_price' => 1110,
                'product_discount_type' => 'fixed',
                'product_discount' => 0,
                'product_discount_input' => 0,
                'stock' => $this->product->product_quantity,
                'unit' => $this->product->product_unit,
            ],
        ]);

        $component
            ->set('supplier_id', $this->nonPkpSupplier->id)
            ->set('payment_term', $this->codTerm->id)
            ->set('tax_ref_no', 'FP-HIDDEN')
            ->set('is_tax_included', true)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('purchases.index'));

        $purchase = Purchase::query()->latest('id')->with('purchaseDetails')->firstOrFail();
        $detail = $purchase->purchaseDetails->first();

        $this->assertNotNull($detail);
        $this->assertFalse((bool) $purchase->is_tax_included);
        $this->assertNull($purchase->tax_ref_no);
        $this->assertSame(0.0, (float) $purchase->tax_amount);
        $this->assertNull($detail->tax_id);
        $this->assertSame(0.0, (float) $detail->product_tax_amount);
        $this->assertSame(1000.0, (float) $detail->sub_total);
    }

    public function test_product_cart_mount_dispatches_tax_included_event_for_new_purchase(): void
    {
        session(['setting_id' => $this->pkpSetting->id]);

        Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->assertSet('is_tax_included', true)
            ->assertDispatched('taxIncludedUpdated', fn ($event, $params) => ($params[0] ?? null) === true);
    }

    public function test_product_cart_mount_dispatches_existing_purchase_tax_included_value(): void
    {
        session(['setting_id' => $this->pkpSetting->id]);

        $purchase = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'supplier_id' => $this->supplier->id,
            'payment_term_id' => $this->codTerm->id,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'paid_amount' => 0,
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'due_amount' => 1000,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'is_tax_included' => false,
            'setting_id' => $this->pkpSetting->id,
        ]);

        Livewire::test(ProductCart::class, ['cartInstance' => 'purchase', 'data' => $purchase])
            ->assertSet('is_tax_included', false)
            ->assertDispatched('taxIncludedUpdated', fn ($event, $params) => ($params[0] ?? null) === false);
    }

    public function test_create_form_receives_tax_included_update_event_value(): void
    {
        session(['setting_id' => $this->pkpSetting->id]);

        Livewire::test(CreateForm::class, ['idempotencyToken' => 'token-tax-sync'])
            ->assertSet('is_tax_included', true)
            ->call('handleTaxIncludedUpdated', false)
            ->assertSet('is_tax_included', false);
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

    public function test_pkp_submit_accepts_mixed_row_tax_persistence_after_tax_included_toggle(): void
    {
        session(['setting_id' => $this->pkpSetting->id]);

        $defaultTax = Tax::create(['name' => 'PPN Default', 'value' => 11, 'is_default' => true]);
        $specialTax = Tax::create(['name' => 'PPN Special', 'value' => 12, 'is_default' => false]);

        $createForm = Livewire::test(CreateForm::class, ['idempotencyToken' => 'token-mixed-pkp']);

        $firstRowId = $this->seedPurchaseCartRow($this->product, null);
        $secondRowId = $this->seedPurchaseCartRow($this->secondProduct, null);

        $cartComponent = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase']);
        $cartRows = Cart::instance('purchase')->content()->keyBy('id');

        $cartComponent
            ->call('updateTax', $cartRows[$this->product->id]->rowId, $this->product->id, (string) $defaultTax->id)
            ->call('updateTax', $cartRows[$this->secondProduct->id]->rowId, $this->secondProduct->id, (string) $specialTax->id)
            ->set('is_tax_included', false)
            ->call('handleTaxIncluded')
            ->set('is_tax_included', true)
            ->call('handleTaxIncluded');

        $createForm
            ->set('supplier_id', $this->supplier->id)
            ->set('payment_term', $this->codTerm->id)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('purchases.index'));

        $purchase = \Modules\Purchase\Entities\Purchase::latest('id')->with('purchaseDetails')->first();

        $this->assertNotNull($purchase);
        $this->assertTrue((bool) $purchase->is_tax_included);
        $this->assertDatabaseHas('purchase_details', [
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'tax_id' => $defaultTax->id,
        ]);
        $this->assertDatabaseHas('purchase_details', [
            'purchase_id' => $purchase->id,
            'product_id' => $this->secondProduct->id,
            'tax_id' => $specialTax->id,
        ]);
    }

    private function seedPurchaseCartRow(Product $product, ?int $productTaxId): string
    {
        return Cart::instance('purchase')->add([
            'id' => $product->id,
            'name' => $product->product_name,
            'qty' => 1,
            'price' => 1000,
            'weight' => 1,
            'options' => [
                'sub_total' => 1000,
                'sub_total_before_tax' => 1000,
                'product_tax_amount' => 0,
                'code' => $product->product_code,
                'product_tax' => $productTaxId,
                'unit_price' => 1000,
                'product_discount_type' => 'fixed',
                'product_discount' => 0,
                'product_discount_input' => 0,
                'stock' => $product->product_quantity,
                'unit' => $product->product_unit,
            ],
        ])->rowId;
    }
}
