<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Purchase\EditForm;
use App\Livewire\Purchase\ProductCart;
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

class PurchaseEditPkpTaxTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected PaymentTerm $codTerm;
    protected Supplier $supplier;
    protected Product $product;
    protected Product $secondProduct;

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
            'company_name' => 'PKP Edit Setting',
            'company_email' => 'pkp-edit@example.com',
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
            'product_name' => 'PKP Product',
            'product_code' => 'PKP-001',
            'product_quantity' => 100,
            'setting_id' => $this->setting->id,
            'product_cost' => 1000,
            'product_price' => 1000,
            'product_unit' => 'pcs',
        ]);

        $this->secondProduct = Product::create([
            'product_name' => 'PKP Product Two',
            'product_code' => 'PKP-002',
            'product_quantity' => 100,
            'setting_id' => $this->setting->id,
            'product_cost' => 1000,
            'product_price' => 1000,
            'product_unit' => 'pcs',
        ]);

        Cart::instance('purchase')->destroy();
    }

    public function test_pkp_edit_submit_fails_when_any_item_has_no_tax(): void
    {
        $purchase = $this->createPurchaseWithTax(null);

        Livewire::test(EditForm::class, ['purchaseId' => $purchase->id])
            ->call('submit')
            ->assertHasErrors(['cart']);

        $this->assertDatabaseHas('purchases', [
            'id' => $purchase->id,
            'total_amount' => 1000,
        ]);
    }

    public function test_pkp_edit_submit_succeeds_when_all_items_have_tax(): void
    {
        $tax = Tax::create([
            'name' => 'PPN 11',
            'value' => 11,
        ]);
        $purchase = $this->createPurchaseWithTax($tax->id);

        Livewire::test(EditForm::class, ['purchaseId' => $purchase->id])
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('purchases.index'));

        $purchase->refresh();
        $this->assertSame(1110.0, (float) $purchase->total_amount);
        $this->assertDatabaseHas('purchase_details', [
            'purchase_id' => $purchase->id,
            'tax_id' => $tax->id,
        ]);
    }

    public function test_pkp_edit_submit_preserves_mixed_row_taxes_after_tax_included_toggle(): void
    {
        $defaultTax = Tax::create([
            'name' => 'PPN Default',
            'value' => 11,
            'is_default' => true,
        ]);

        $specialTax = Tax::create([
            'name' => 'PPN Special',
            'value' => 12,
            'is_default' => false,
        ]);

        $purchase = $this->createPurchaseWithLines([
            ['product' => $this->product, 'tax_id' => null],
            ['product' => $this->secondProduct, 'tax_id' => null],
        ]);

        $editForm = Livewire::test(EditForm::class, ['purchaseId' => $purchase->id]);
        $cartRows = Cart::instance('purchase')->content()->values();

        Livewire::test(ProductCart::class, ['cartInstance' => 'purchase', 'data' => $purchase])
            ->call('updateTax', $cartRows[0]->rowId, $this->product->id, (string) $defaultTax->id)
            ->call('updateTax', $cartRows[1]->rowId, $this->secondProduct->id, (string) $specialTax->id)
            ->set('is_tax_included', true)
            ->call('handleTaxIncluded')
            ->set('is_tax_included', false)
            ->call('handleTaxIncluded');

        $editForm
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('purchases.index'));

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

    private function createPurchaseWithTax(?int $taxId): Purchase
    {
        return $this->createPurchaseWithLines([
            ['product' => $this->product, 'tax_id' => $taxId],
        ]);
    }

    private function createPurchaseWithLines(array $lines): Purchase
    {
        $subTotal = 0.0;
        $taxAmount = 0.0;

        foreach ($lines as $line) {
            $lineSubTotal = $line['tax_id'] ? 1110.0 : 1000.0;
            $lineTaxAmount = $line['tax_id'] ? 110.0 : 0.0;
            $subTotal += $lineSubTotal;
            $taxAmount += $lineTaxAmount;
        }

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

        foreach ($lines as $line) {
            $product = $line['product'];
            $lineSubTotal = $line['tax_id'] ? 1110.0 : 1000.0;
            $lineTaxAmount = $line['tax_id'] ? 110.0 : 0.0;

            PurchaseDetail::create([
                'purchase_id' => $purchase->id,
                'product_id' => $product->id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'quantity' => 1,
                'price' => $lineSubTotal,
                'unit_price' => 1000,
                'sub_total' => $lineSubTotal,
                'product_discount_amount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax_amount' => $lineTaxAmount,
                'tax_id' => $line['tax_id'],
            ]);
        }

        return $purchase;
    }
}
