<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Sale\ProductCart;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

class SaleProductCartPkpTaxReconciliationTest extends TestCase
{
    use RefreshDatabase;

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

        $setting = Setting::create([
            'company_name' => 'PKP Sales Co',
            'company_email' => 'pkp-sales@example.com',
            'company_phone' => '123456',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => true,
        ]);

        session(['setting_id' => $setting->id]);

        $this->product = Product::create([
            'product_name' => 'Recon Sale Product',
            'product_code' => 'SALE-RECON-001',
            'product_quantity' => 20,
            'setting_id' => $setting->id,
            'product_cost' => 1000,
            'product_price' => 1110,
            'product_unit' => 'pcs',
        ]);

        $this->secondProduct = Product::create([
            'product_name' => 'Recon Sale Product Two',
            'product_code' => 'SALE-RECON-002',
            'product_quantity' => 20,
            'setting_id' => $setting->id,
            'product_cost' => 1000,
            'product_price' => 1110,
            'product_unit' => 'pcs',
        ]);

        Cart::instance('sale')->destroy();
    }

    public function test_pkp_default_tax_does_not_reconcile_existing_sale_cart_row_missing_tax_on_mount(): void
    {
        $defaultTax = Tax::create([
            'name' => 'PPN 11',
            'value' => 11,
            'is_default' => true,
        ]);

        Tax::create([
            'name' => 'PPN 12',
            'value' => 12,
            'is_default' => false,
        ]);

        $this->seedCartRow(productTaxId: null);

        Livewire::test(ProductCart::class, ['cartInstance' => 'sale']);

        $cartItem = Cart::instance('sale')->content()->first();

        $this->assertNotNull($cartItem);
        $this->assertNull($cartItem->options->product_tax);
    }

    public function test_pkp_reconciliation_does_not_override_existing_sale_row_tax(): void
    {
        Tax::create([
            'name' => 'PPN Default',
            'value' => 11,
            'is_default' => true,
        ]);

        $explicitTax = Tax::create([
            'name' => 'PPN Special',
            'value' => 12,
            'is_default' => false,
        ]);

        $this->seedCartRow(productTaxId: $explicitTax->id);

        Livewire::test(ProductCart::class, ['cartInstance' => 'sale']);

        $cartItem = Cart::instance('sale')->content()->first();

        $this->assertNotNull($cartItem);
        $this->assertSame($explicitTax->id, (int) $cartItem->options->product_tax);
    }

    public function test_pkp_without_default_tax_does_not_reconcile_missing_row_tax_using_latest_tax(): void
    {
        Tax::create([
            'name' => 'PPN 10',
            'value' => 10,
            'is_default' => false,
        ]);

        $latestTax = Tax::create([
            'name' => 'PPN 11',
            'value' => 11,
            'is_default' => false,
        ]);

        $this->seedCartRow(productTaxId: null);

        Livewire::test(ProductCart::class, ['cartInstance' => 'sale']);

        $cartItem = Cart::instance('sale')->content()->first();

        $this->assertNotNull($cartItem);
        $this->assertNull($cartItem->options->product_tax);
    }

    public function test_pkp_without_any_tax_rows_keeps_cart_row_tax_null(): void
    {
        $this->seedCartRow(productTaxId: null);

        Livewire::test(ProductCart::class, ['cartInstance' => 'sale']);

        $cartItem = Cart::instance('sale')->content()->first();

        $this->assertNotNull($cartItem);
        $this->assertNull($cartItem->options->product_tax);
    }

    public function test_pkp_without_any_tax_rows_renders_placeholder_as_selected(): void
    {
        $this->seedCartRow(productTaxId: null);

        $component = Livewire::test(ProductCart::class, ['cartInstance' => 'sale']);
        $html = $component->html();

        $this->assertMatchesRegularExpression(
            '/<option value=\"\"[^>]*disabled[^>]*selected[^>]*>\s*Wajib Pilih Pajak\s*<\/option>/',
            $html
        );
    }

    public function test_first_explicit_tax_selection_persists_immediately_for_taxless_pkp_sale_row(): void
    {
        $explicitTax = Tax::create([
            'name' => 'PPN 11',
            'value' => 11,
            'is_default' => false,
        ]);

        $this->seedCartRow(productTaxId: null);

        $cartItem = Cart::instance('sale')->content()->first();

        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('updateTax', $cartItem->rowId, $cartItem->id, (string) $explicitTax->id)
            ->assertSet('product_tax.' . $cartItem->id, $explicitTax->id);

        $updatedCartItem = Cart::instance('sale')->content()->first();

        $this->assertNotNull($updatedCartItem);
        $this->assertSame($explicitTax->id, (int) $updatedCartItem->options->product_tax);
        $this->assertEqualsWithDelta(1110.0, (float) $updatedCartItem->options->sub_total, 0.0001);
        $this->assertEqualsWithDelta(1000.0, (float) $updatedCartItem->options->sub_total_before_tax, 0.0001);
        $this->assertEqualsWithDelta(
            110.0,
            (float) $updatedCartItem->options->sub_total - (float) $updatedCartItem->options->sub_total_before_tax,
            0.0001
        );
    }

    public function test_mixed_pkp_sale_rows_persist_independent_default_and_non_default_tax_selections(): void
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

        $firstRow = $this->seedCartRowForProduct($this->product, 'SALE-LINE-1', null);
        $secondRow = $this->seedCartRowForProduct($this->secondProduct, 'SALE-LINE-2', null);

        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('updateTax', $firstRow->rowId, $firstRow->id, (string) $defaultTax->id)
            ->call('updateTax', $secondRow->rowId, $secondRow->id, (string) $specialTax->id)
            ->assertSet('product_tax.' . $firstRow->id, $defaultTax->id)
            ->assertSet('product_tax.' . $secondRow->id, $specialTax->id);

        $cartItems = Cart::instance('sale')->content()->keyBy('id');

        $this->assertSame($defaultTax->id, (int) $cartItems[$firstRow->id]->options->product_tax);
        $this->assertSame($specialTax->id, (int) $cartItems[$secondRow->id]->options->product_tax);
    }

    public function test_tax_included_toggle_preserves_mixed_pkp_sale_row_tax_selections(): void
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

        $firstRow = $this->seedCartRowForProduct($this->product, 'SALE-LINE-1', null);
        $secondRow = $this->seedCartRowForProduct($this->secondProduct, 'SALE-LINE-2', null);

        Livewire::test(ProductCart::class, ['cartInstance' => 'sale'])
            ->call('updateTax', $firstRow->rowId, $firstRow->id, (string) $defaultTax->id)
            ->call('updateTax', $secondRow->rowId, $secondRow->id, (string) $specialTax->id)
            ->set('is_tax_included', false)
            ->call('handleTaxIncluded')
            ->set('is_tax_included', true)
            ->call('handleTaxIncluded');

        $cartItems = Cart::instance('sale')->content()->keyBy('id');

        $this->assertSame($defaultTax->id, (int) $cartItems[$firstRow->id]->options->product_tax);
        $this->assertSame($specialTax->id, (int) $cartItems[$secondRow->id]->options->product_tax);
    }

    private function seedCartRow(?int $productTaxId): void
    {
        $this->seedCartRowForProduct($this->product, 'SALE-LINE-1', $productTaxId);
    }

    private function seedCartRowForProduct(Product $product, string $lineId, ?int $productTaxId)
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
}
