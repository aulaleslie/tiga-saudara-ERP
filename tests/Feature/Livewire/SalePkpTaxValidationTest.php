<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Sale\CreateForm;
use App\Livewire\Sale\EditForm;
use App\Livewire\Sale\ProductCart as SaleProductCart;
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

class SalePkpTaxValidationTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $setting;
    protected PaymentTerm $paymentTerm;
    protected Customer $customer;
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
            'company_name' => 'PKP Sales Setting',
            'company_email' => 'pkp-sales@example.com',
            'company_phone' => '123456',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => true,
        ]);

        session(['setting_id' => $this->setting->id]);

        $this->paymentTerm = PaymentTerm::create([
            'name' => 'Cash',
            'longevity' => 0,
        ]);

        $this->customer = Customer::factory()->create([
            'setting_id' => $this->setting->id,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        $this->product = Product::create([
            'product_name' => 'Sale PKP Product',
            'product_code' => 'SALE-PKP-001',
            'product_quantity' => 100,
            'setting_id' => $this->setting->id,
            'product_cost' => 1000,
            'product_price' => 1110,
            'product_unit' => 'pcs',
        ]);

        $this->secondProduct = Product::create([
            'product_name' => 'Sale PKP Product Two',
            'product_code' => 'SALE-PKP-002',
            'product_quantity' => 100,
            'setting_id' => $this->setting->id,
            'product_cost' => 1000,
            'product_price' => 1110,
            'product_unit' => 'pcs',
        ]);

        Cart::instance('sale')->destroy();
    }

    public function test_pkp_sale_create_submit_accepts_mixed_row_taxes_after_tax_included_toggle(): void
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

        $firstRow = $this->seedSaleCartRow($this->product, 'SALE-LINE-1', null);
        $secondRow = $this->seedSaleCartRow($this->secondProduct, 'SALE-LINE-2', null);

        Livewire::test(SaleProductCart::class, ['cartInstance' => 'sale'])
            ->call('updateTax', $firstRow->rowId, $firstRow->id, (string) $defaultTax->id)
            ->call('updateTax', $secondRow->rowId, $secondRow->id, (string) $specialTax->id)
            ->set('is_tax_included', false)
            ->call('handleTaxIncluded')
            ->set('is_tax_included', true)
            ->call('handleTaxIncluded');

        Livewire::test(CreateForm::class, ['idempotencyToken' => 'sale-mixed-pkp'])
            ->set('customerId', $this->customer->id)
            ->set('date', now()->format('Y-m-d'))
            ->set('dueDate', now()->format('Y-m-d'))
            ->set('paymentTermId', $this->paymentTerm->id)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('sales.index'));

        $sale = Sale::latest('id')->first();

        $this->assertNotNull($sale);
        $this->assertDatabaseHas('sale_details', [
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'tax_id' => $defaultTax->id,
        ]);
        $this->assertDatabaseHas('sale_details', [
            'sale_id' => $sale->id,
            'product_id' => $this->secondProduct->id,
            'tax_id' => $specialTax->id,
        ]);
    }

    public function test_pkp_sale_edit_update_requires_persisted_taxes(): void
    {
        $sale = $this->createSaleWithLines([
            ['product' => $this->product, 'tax_id' => null],
        ]);

        $editForm = Livewire::test(EditForm::class, ['sale' => $sale]);

        $editForm
            ->call('update')
            ->assertHasErrors(['paymentTermId']);
    }

    public function test_pkp_sale_edit_update_preserves_mixed_row_taxes_after_tax_included_toggle(): void
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

        $sale = $this->createSaleWithLines([
            ['product' => $this->product, 'tax_id' => null],
            ['product' => $this->secondProduct, 'tax_id' => null],
        ]);

        $editForm = Livewire::test(EditForm::class, ['sale' => $sale]);
        $cartRows = Cart::instance('sale')->content()->values();

        Livewire::test(SaleProductCart::class, ['cartInstance' => 'sale', 'data' => $sale])
            ->call('updateTax', $cartRows[0]->rowId, $cartRows[0]->id, (string) $defaultTax->id)
            ->call('updateTax', $cartRows[1]->rowId, $cartRows[1]->id, (string) $specialTax->id)
            ->set('is_tax_included', false)
            ->call('handleTaxIncluded')
            ->set('is_tax_included', true)
            ->call('handleTaxIncluded');

        $editForm
            ->call('update')
            ->assertHasNoErrors()
            ->assertRedirect(route('sales.index'));

        $this->assertDatabaseHas('sale_details', [
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'tax_id' => $defaultTax->id,
        ]);
        $this->assertDatabaseHas('sale_details', [
            'sale_id' => $sale->id,
            'product_id' => $this->secondProduct->id,
            'tax_id' => $specialTax->id,
        ]);
    }

    private function seedSaleCartRow(Product $product, string $lineId, ?int $productTaxId)
    {
        return Cart::instance('sale')->add([
            'id' => $lineId,
            'name' => $product->product_name,
            'qty' => 1,
            'price' => 1110,
            'weight' => 1,
            'options' => [
                'product_id' => $product->id,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'sub_total' => 1110,
                'sub_total_before_tax' => 1110,
                'product_tax_amount' => 0,
                'code' => $product->product_code,
                'stock' => $product->product_quantity,
                'unit' => $product->product_unit,
                'product_tax' => $productTaxId,
                'unit_price' => 1110,
                'bundle_items' => [],
                'bundle_price' => 0,
            ],
        ]);
    }

    private function createSaleWithLines(array $lines): Sale
    {
        $subTotal = 0.0;
        $taxAmount = 0.0;

        foreach ($lines as $line) {
            $lineSubTotal = $line['tax_id'] ? 1110.0 : 1000.0;
            $lineTaxAmount = $line['tax_id'] ? 110.0 : 0.0;
            $subTotal += $lineSubTotal;
            $taxAmount += $lineTaxAmount;
        }

        $sale = Sale::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => $taxAmount,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => $subTotal,
            'paid_amount' => 0,
            'due_amount' => $subTotal,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'note' => null,
            'setting_id' => $this->setting->id,
            'payment_term_id' => $this->paymentTerm->id,
            'is_tax_included' => false,
        ]);

        foreach ($lines as $line) {
            $product = $line['product'];
            $lineSubTotal = $line['tax_id'] ? 1110.0 : 1000.0;
            $lineTaxAmount = $line['tax_id'] ? 110.0 : 0.0;

            SaleDetails::create([
                'sale_id' => $sale->id,
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

        return $sale->fresh(['saleDetails', 'tags']);
    }
}
