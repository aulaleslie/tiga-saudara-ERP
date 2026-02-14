<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Purchase\EditForm;
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

class PurchaseEditTaxHydrationTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected PaymentTerm $codTerm;
    protected Supplier $supplier;
    protected Product $product;

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
            'company_name' => 'Hydration Setting',
            'company_email' => 'hydration@example.com',
            'company_phone' => '1234567890',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => true,
        ]);
        session(['setting_id' => $this->setting->id]);

        $this->codTerm = PaymentTerm::query()->firstOrCreate(
            ['name' => 'Cash on Delivery'],
            ['longevity' => 0]
        );

        $this->supplier = Supplier::factory()->create([
            'setting_id' => $this->setting->id,
            'payment_term_id' => $this->codTerm->id,
        ]);

        $this->product = Product::create([
            'product_name' => 'Hydration Product',
            'product_code' => 'HYD-001',
            'product_quantity' => 100,
            'setting_id' => $this->setting->id,
            'product_cost' => 1000,
            'product_price' => 1000,
            'product_unit' => 'pcs',
        ]);

        Cart::instance('purchase')->destroy();
    }

    protected function tearDown(): void
    {
        Cart::instance('purchase')->destroy();

        parent::tearDown();
    }

    public function test_edit_restore_cart_uses_detail_tax_amount_for_subtotal_before_tax(): void
    {
        $tax = Tax::create([
            'name' => 'PPN 11',
            'value' => 11,
        ]);

        $purchase = $this->createPurchaseWithDetail($tax->id, 1110.0, 110.0);

        Livewire::test(EditForm::class, ['purchaseId' => $purchase->id]);

        $cartItem = Cart::instance('purchase')->content()->first();
        $this->assertNotNull($cartItem);

        $subTotal = (float) $cartItem->options->sub_total;
        $subTotalBeforeTax = (float) $cartItem->options->sub_total_before_tax;

        $this->assertEqualsWithDelta(1000.0, $subTotalBeforeTax, 0.0001);
        $this->assertEqualsWithDelta(110.0, $subTotal - $subTotalBeforeTax, 0.0001);
    }

    public function test_edit_load_preserves_non_tax_item_subtotal_before_tax(): void
    {
        $purchase = $this->createPurchaseWithDetail(null, 1000.0, 0.0);

        Livewire::test(EditForm::class, ['purchaseId' => $purchase->id]);

        $cartItem = Cart::instance('purchase')->content()->first();
        $this->assertNotNull($cartItem);

        $subTotal = (float) $cartItem->options->sub_total;
        $subTotalBeforeTax = (float) $cartItem->options->sub_total_before_tax;

        $this->assertEqualsWithDelta($subTotal, $subTotalBeforeTax, 0.0001);
        $this->assertEqualsWithDelta(0.0, $subTotal - $subTotalBeforeTax, 0.0001);
    }

    public function test_edit_submit_keeps_tax_amount_consistent_after_load(): void
    {
        $tax = Tax::create([
            'name' => 'PPN 11',
            'value' => 11,
        ]);

        $purchase = $this->createPurchaseWithDetail($tax->id, 1110.0, 110.0);

        Livewire::test(EditForm::class, ['purchaseId' => $purchase->id])
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('purchases.index'));

        $purchase->refresh();

        $this->assertEqualsWithDelta(110.0, (float) $purchase->tax_amount, 0.0001);
        $this->assertEqualsWithDelta(1110.0, (float) $purchase->total_amount, 0.0001);

        $detail = PurchaseDetail::query()->where('purchase_id', $purchase->id)->firstOrFail();

        $this->assertSame($tax->id, $detail->tax_id);
        $this->assertEqualsWithDelta(110.0, (float) $detail->product_tax_amount, 0.0001);
        $this->assertEqualsWithDelta(1110.0, (float) $detail->sub_total, 0.0001);
    }

    private function createPurchaseWithDetail(?int $taxId, float $subTotal, float $taxAmount): Purchase
    {
        $purchase = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'supplier_id' => $this->supplier->id,
            'supplier_purchase_number' => null,
            'tax_ref_no' => null,
            'tax_id' => null,
            'tax_percentage' => 0,
            'tax_amount' => $taxAmount,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => $subTotal,
            'paid_amount' => 0,
            'due_amount' => $subTotal,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => null,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'payment_term_id' => $this->codTerm->id,
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => $subTotal,
            'unit_price' => $subTotal - $taxAmount,
            'sub_total' => $subTotal,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => $taxAmount,
            'tax_id' => $taxId,
        ]);

        return $purchase;
    }
}
