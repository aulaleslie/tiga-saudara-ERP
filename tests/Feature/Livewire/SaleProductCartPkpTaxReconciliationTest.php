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

    private function seedCartRow(?int $productTaxId): void
    {
        Cart::instance('sale')->add([
            'id' => 'SALE-LINE-1',
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 1110,
            'weight' => 1,
            'options' => [
                'product_id' => $this->product->id,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'sub_total' => 1110,
                'sub_total_before_tax' => 1110,
                'code' => $this->product->product_code,
                'stock' => $this->product->product_quantity,
                'unit' => $this->product->product_unit,
                'product_tax' => $productTaxId,
                'unit_price' => 1110,
                'bundle_items' => [],
                'bundle_price' => 0,
            ],
        ]);
    }
}
