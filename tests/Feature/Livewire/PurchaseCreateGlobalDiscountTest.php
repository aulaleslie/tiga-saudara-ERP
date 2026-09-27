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
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchaseCreateGlobalDiscountTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;
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
            'company_name' => 'Regular Company', 'company_email' => 'r@ex.com', 'company_phone' => '1', 'default_currency_id' => $currency->id, 'default_currency_position' => 'prefix', 'notification_email' => 'a@b.com', 'footer_text' => 'f', 'company_address' => 'a', 'is_pkp' => false,
        ]);
        session(['setting_id' => $this->setting->id]);

        $this->codTerm = PaymentTerm::create(['name' => 'Cash on Delivery', 'longevity' => 0]);

        $this->product = Product::create([
            'product_name' => 'Product X', 'product_code' => 'PX', 'product_quantity' => 100, 'setting_id' => $this->setting->id, 'product_cost' => 100000, 'product_price' => 100000, 'product_unit' => 'pcs',
        ]);

        $this->supplier = Supplier::factory()->create(['setting_id' => $this->setting->id, 'payment_term_id' => $this->codTerm->id]);

        Cart::instance('purchase')->destroy();
    }

    public function test_percentage_discount_stored_correctly()
    {
        $component = Livewire::test(CreateForm::class, ['idempotencyToken' => 'token-4']);

        Cart::instance('purchase')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 100000,
            'weight' => 1,
            'options' => [
                'sub_total' => 100000,
                'sub_total_before_tax' => 100000,
                'code' => $this->product->product_code,
                'product_tax' => null,
                'unit_price' => 100000,
                'product_discount_type' => 'fixed',
                'product_discount' => 0,
            ],
        ]);

        $component->set('supplier_id', $this->supplier->id)
            ->set('payment_term', $this->codTerm->id)
            ->dispatch('globalDiscountUpdated', 10)
            ->dispatch('globalDiscountTypeUpdated', 'percentage')
            ->call('submit');

        $this->assertDatabaseHas('purchases', [
            'discount_percentage' => 10,
            'discount_amount' => 0,
            'total_amount' => 90000, // 100000 - 10%
        ]);
    }

    public function test_fixed_discount_50_not_treated_as_percentage()
    {
        $component = Livewire::test(CreateForm::class, ['idempotencyToken' => 'token-5']);

        Cart::instance('purchase')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 100000,
            'weight' => 1,
            'options' => [
                'sub_total' => 100000,
                'sub_total_before_tax' => 100000,
                'code' => $this->product->product_code,
                'product_tax' => null,
                'unit_price' => 100000,
                'product_discount_type' => 'fixed',
                'product_discount' => 0,
            ],
        ]);

        $component->set('supplier_id', $this->supplier->id)
            ->set('payment_term', $this->codTerm->id)
            ->dispatch('globalDiscountUpdated', 50)
            ->dispatch('globalDiscountTypeUpdated', 'fixed') // Explicitly fixed
            ->call('submit');

        $this->assertDatabaseHas('purchases', [
            'discount_percentage' => 0,
            'discount_amount' => 50,
            'total_amount' => 99950, // 100000 - 50
        ]);
    }

    public function test_fixed_discount_large_amount_stored_correctly()
    {
        $component = Livewire::test(CreateForm::class, ['idempotencyToken' => 'token-6']);

        Cart::instance('purchase')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 100000,
            'weight' => 1,
            'options' => [
                'sub_total' => 100000,
                'sub_total_before_tax' => 100000,
                'code' => $this->product->product_code,
                'product_tax' => null,
                'unit_price' => 100000,
                'product_discount_type' => 'fixed',
                'product_discount' => 0,
            ],
        ]);

        $component->set('supplier_id', $this->supplier->id)
            ->set('payment_term', $this->codTerm->id)
            ->dispatch('globalDiscountUpdated', 5000)
            ->dispatch('globalDiscountTypeUpdated', 'fixed')
            ->call('submit');

        $this->assertDatabaseHas('purchases', [
            'discount_percentage' => 0,
            'discount_amount' => 5000,
            'total_amount' => 95000, // 100000 - 5000
        ]);
    }

    public function test_purchase_creation_persists_drafted_status_correct_totals_and_details_atomically()
    {
        $component = Livewire::test(CreateForm::class, ['idempotencyToken' => 'token-7']);

        Cart::instance('purchase')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 2,
            'price' => 100000,
            'weight' => 1,
            'options' => [
                'sub_total' => 200000,
                'sub_total_before_tax' => 200000,
                'code' => $this->product->product_code,
                'product_tax' => null,
                'unit_price' => 100000,
                'product_discount_type' => 'fixed',
                'product_discount' => 0,
            ],
        ]);

        $component->set('supplier_id', $this->supplier->id)
            ->set('payment_term', $this->codTerm->id)
            ->dispatch('globalDiscountUpdated', 10)
            ->dispatch('globalDiscountTypeUpdated', 'percentage')
            ->call('submit');

        $purchase = Purchase::where('setting_id', $this->setting->id)->latest('id')->first();

        $this->assertNotNull($purchase);
        $this->assertSame(Purchase::STATUS_DRAFTED, $purchase->status);
        $this->assertEquals(10, $purchase->discount_percentage);
        $this->assertEquals(0, $purchase->discount_amount);
        $this->assertEquals(180000, $purchase->total_amount); // 200000 - 10%
        $this->assertEquals(180000, $purchase->due_amount);
        $this->assertNotEmpty($purchase->reference);

        $details = PurchaseDetail::where('purchase_id', $purchase->id)->get();
        $this->assertCount(1, $details);
        $this->assertEquals(2, $details->first()->quantity);
        $this->assertEquals($this->product->id, $details->first()->product_id);

        // Reference and counter commit atomically: the sequence row must reflect the same allocation.
        $seqRow = \App\Services\Sequence\DocumentSequence::query()
            ->where('setting_id', $this->setting->id)
            ->where('document_type', 'purchase')
            ->first();
        $this->assertNotNull($seqRow);
        $this->assertStringEndsWith(sprintf('%05d', $seqRow->last_number), $purchase->reference);
    }
}
