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

class PurchaseEditNonPkpNormalizationTest extends TestCase
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
            'company_name' => 'Edit Non PKP',
            'company_email' => 'edit-nonpkp@example.com',
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
            'product_name' => 'Restored Tax Product',
            'product_code' => 'RT-001',
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

    public function test_non_pkp_edit_load_clears_hidden_tax_state_and_hides_tax_ref_input(): void
    {
        $purchase = $this->createTaxBearingPurchase();

        Livewire::test(EditForm::class, ['purchaseId' => $purchase->id])
            ->assertDontSee('Nomor Faktur Pajak');

        $cartItem = Cart::instance('purchase')->content()->first();

        $this->assertNotNull($cartItem);
        $this->assertNull($cartItem->options->product_tax);
        $this->assertSame(1000.0, (float) $cartItem->options->sub_total);
        $this->assertSame(1000.0, (float) $cartItem->options->sub_total_before_tax);
        $this->assertSame(0.0, (float) $cartItem->options->product_tax_amount);
    }

    public function test_non_pkp_edit_submit_normalizes_restored_taxed_detail(): void
    {
        $purchase = $this->createTaxBearingPurchase();

        Livewire::test(EditForm::class, ['purchaseId' => $purchase->id])
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('purchases.index'));

        $purchase->refresh();
        $detail = PurchaseDetail::query()->where('purchase_id', $purchase->id)->firstOrFail();

        $this->assertSame(0.0, (float) $purchase->tax_amount);
        $this->assertSame(1000.0, (float) $purchase->total_amount);
        $this->assertSame(1000.0, (float) $purchase->due_amount);
        $this->assertNull($detail->tax_id);
        $this->assertSame(0.0, (float) $detail->product_tax_amount);
        $this->assertSame(1000.0, (float) $detail->sub_total);
    }

    private function createTaxBearingPurchase(): Purchase
    {
        $purchase = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'supplier_id' => $this->supplier->id,
            'supplier_purchase_number' => null,
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
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => null,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
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

        return $purchase;
    }
}
