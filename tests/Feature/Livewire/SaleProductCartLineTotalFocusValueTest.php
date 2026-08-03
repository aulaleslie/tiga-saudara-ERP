<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Sale\ProductCart;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Currency\Entities\Currency;
use Tests\TestCase;

class SaleProductCartLineTotalFocusValueTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Product $product;
    protected Tax $tax11;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'PKP Setting',
            'company_email' => 'pkp@example.com',
            'company_phone' => '123456789',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => true,
        ]);

        $this->tax11 = Tax::create([
            'name' => '11% Tax',
            'value' => 11,
            'is_default' => true,
        ]);

        $this->product = Product::create([
            'product_name' => 'Test Sale Product',
            'product_code' => 'SALE-001',
            'product_quantity' => 100,
            'setting_id' => $this->setting->id,
            'product_cost' => 1000,
            'product_price' => 1000,
            'product_unit' => 'pcs',
        ]);

        ProductPrice::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->setting->id,
            'last_purchase_price' => 41892,
            'average_purchase_price' => 41892,
            'sale_price' => 50000,
            'purchase_tax_id' => $this->tax11->id,
            'sale_tax_id' => $this->tax11->id,
        ]);

        $this->customer = Customer::create([
            'customer_name' => 'Test Customer',
            'contact_name' => 'Contact',
            'customer_email' => 'customer@test.com',
            'customer_phone' => '123456789',
            'tier' => 'RETAIL',
        ]);

        session()->put('setting_id', $this->setting->id);
    }

    public function test_sale_cart_46500_line_total_renders_with_stable_wire_keys()
    {
        // Regression: a line total of 46500 (50000 base - 3500 discount) must render with stable wire:key attributes
        // and the line-total input must have a distinct wire:key for proper Alpine initialization.
        // Standard (non-bundled) rows only—bundled rows have read-only Total Baris.
        // This structural coverage verifies that stale DOM reuse cannot occur.
        Cart::instance('sale')->destroy();

        // Seed cart directly with exact canonical sub_total = 46500
        Cart::instance('sale')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 50000,
            'weight' => 1,
            'options' => [
                'product_id' => $this->product->id,
                'product_discount' => 3500,
                'product_discount_input' => 3500,
                'product_discount_type' => 'fixed',
                'sub_total' => 46500,
                'sub_total_before_tax' => 46500,
                'product_tax_amount' => 0,
                'code' => $this->product->product_code,
                'stock' => 100,
                'unit' => 'pc',
                'sale_price' => 50000,
                'product_tax' => $this->tax11->id,
            ],
        ]);

        $component = Livewire::test(ProductCart::class, [
            'cartInstance' => 'sale',
            'selectedSettingId' => $this->setting->id,
        ]);

        $cartItem = Cart::instance('sale')->content()->first();
        $rowId = $cartItem->rowId;
        $productId = $cartItem->id;

        // Verify the rendered HTML contains stable wire:key attributes
        $html = $component->html();
        $this->assertStringContainsString("wire:key=\"cart-item-{$productId}\"", $html,
            'Cart row must have stable wire:key to prevent DOM reuse');
        $this->assertStringContainsString("wire:key=\"sale-line-total-input-{$rowId}\"", $html,
            'Line-total input must have distinct wire:key for Alpine initialization');

        // Verify the Alpine :value directive uses exactly 46500
        $this->assertStringContainsString(":value=\"open ? '46500'", $html,
            'Alpine :value directive must initialize input with full canonical sub_total (46500)');
        $this->assertStringNotContainsString(":value=\"open ? '4650'", $html,
            'Truncated 4650 value must NOT appear in :value directive');
    }

    public function test_sale_committed_replacement_line_total_follows_validation_and_manual_pricing_rules()
    {
        // Verify that committing a replacement Total Baris (50000) recalculates correctly
        // using reverse-calculation rules and manual-pricing semantics.
        Cart::instance('sale')->destroy();

        $component = Livewire::test(ProductCart::class, [
            'cartInstance' => 'sale',
            'selectedSettingId' => $this->setting->id,
        ])
            ->set('setting_id', $this->setting->id)
            ->set('isPkp', true)
            ->set('is_tax_included', false)
            ->call('customerSelected', $this->customer->id)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => $this->product->product_quantity,
                'product_unit' => 'pc',
                'sale_price' => 50000,
            ])
            ->set('product_tax.' . $this->product->id, $this->tax11->id)
            ->call('updateTax', Cart::instance('sale')->content()->first()->rowId, $this->product->id, $this->tax11->id);

        $cartItem = Cart::instance('sale')->content()->first();
        $productId = $cartItem->id;
        $rowId = $cartItem->rowId;

        // Update line total to 50000 and verify it calculates correctly
        $component->set('line_total.' . $productId, 50000)
            ->call('updateLineTotal', $rowId, $productId)
            ->assertViewHas('cart_items', function ($items) {
                $item = $items->first();
                // After reverse calculation, subtotal should be close to 50000
                return abs($item->options->sub_total - 50000) < 1;
            });

        // Verify that the pricing source is marked as manual_line_total
        $component->assertViewHas('cart_items', function ($items) {
            $item = $items->first();
            return ($item->options->pricing_source ?? null) === 'manual_line_total';
        });
    }

    public function test_sale_cart_hydration_46500_line_total_from_persisted_detail()
    {
        // Structural cart-hydration test: when cart is seeded from persisted Sale and SaleDetails
        // (standard non-bundled row) with explicit 46500 line total, the rendered ProductCart must
        // preserve the full value in the Alpine :value directive without truncation or loss of precision.
        //
        // NOTE: This is a cart-hydration structural test, not a full edit-form integration test.
        // It manually seeds Cart::instance() and mounts ProductCart directly, simulating the
        // cart restoration step that occurs in Sale EditForm. A real edit-form integration test
        // (mounting Sales\EditForm → triggering its cart restoration → inspecting child ProductCart)
        // is a separate concern and documented in OpenSpec.
        Cart::instance('sale')->destroy();

        $sale = Sale::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'INV-TEST-' . uniqid(),
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 46500,
            'paid_amount' => 0,
            'due_amount' => 46500,
            'setting_id' => $this->setting->id,
        ]);

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 50000,
            'unit_price' => 50000,
            'product_discount_amount' => 3500,
            'product_discount_type' => 'fixed',
            'sub_total' => 46500,
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        // Simulate the cart restoration that occurs in Sale EditForm
        Cart::instance('sale')->add([
            'id' => $saleDetail->product_id,
            'name' => $saleDetail->product_name,
            'qty' => $saleDetail->quantity,
            'price' => $saleDetail->price,
            'weight' => 1,
            'options' => [
                'product_id' => $saleDetail->product_id,
                'product_discount' => $saleDetail->product_discount_amount,
                'product_discount_input' => $saleDetail->product_discount_amount,
                'product_discount_type' => $saleDetail->product_discount_type,
                'sub_total' => $saleDetail->sub_total,
                'code' => $saleDetail->product_code,
                'stock' => $this->product->product_quantity,
                'product_tax' => $saleDetail->tax_id,
                'unit_price' => $saleDetail->unit_price,
                'sub_total_before_tax' => $saleDetail->sub_total,
                'product_tax_amount' => $saleDetail->product_tax_amount,
            ],
        ]);

        $component = Livewire::test(ProductCart::class, [
            'cartInstance' => 'sale',
            'selectedSettingId' => $this->setting->id,
        ]);

        $cartItem = Cart::instance('sale')->content()->first();
        $rowId = $cartItem->rowId;

        // Verify the rendered HTML contains exactly 46500 in the Alpine :value directive
        $html = $component->html();
        $this->assertStringContainsString(":value=\"open ? '46500'", $html,
            'Alpine :value directive must initialize input with full line total (46500), not truncated');
        $this->assertStringNotContainsString(":value=\"open ? '4650'", $html,
            'Truncated 4650 value must NOT appear in :value directive');

        // Verify stable wire:key attributes
        $this->assertStringContainsString("wire:key=\"sale-line-total-input-{$rowId}\"", $html);

        // Verify component state matches the persisted 46500
        $component->assertViewHas('cart_items', function ($items) {
            $item = $items->first();
            $this->assertEquals(46500, $item->options->sub_total,
                'Cart item sub_total must equal persisted value (46500)');
            return true;
        });
    }
}
