<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Purchase\ProductCart;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Tests\TestCase;

class PurchaseProductCartLineTotalFocusValueTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Product $product;
    protected Tax $tax11;

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
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
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

        session()->put('setting_id', $this->setting->id);
    }

    public function test_purchase_cart_46500_line_total_renders_with_stable_wire_keys()
    {
        // Regression: a line total of 46500 must render with stable wire:key attributes
        // and the line-total input must have a distinct wire:key for proper Alpine initialization.
        // This structural coverage verifies that stale DOM reuse cannot occur.
        Cart::instance('purchase')->destroy();

        // Seed cart directly with exact canonical sub_total = 46500
        Cart::instance('purchase')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 41892,
            'weight' => 1,
            'options' => [
                'product_discount' => 0,
                'product_discount_input' => 0,
                'product_discount_type' => 'fixed',
                'sub_total' => 46500,
                'sub_total_before_tax' => 41892,
                'product_tax_amount' => 4608,
                'code' => $this->product->product_code,
                'stock' => 100,
                'unit' => 'pc',
                'last_purchase_price' => 41892,
                'average_purchase_price' => 41892,
                'product_tax' => $this->tax11->id,
                'unit_price' => 41892,
            ],
        ]);

        $component = Livewire::test(ProductCart::class, [
            'cartInstance' => 'purchase',
            'selectedSettingId' => $this->setting->id,
        ]);

        $cartItem = Cart::instance('purchase')->content()->first();
        $rowId = $cartItem->rowId;
        $productId = $cartItem->id;

        // Verify the rendered HTML contains stable wire:key attributes
        $html = $component->html();
        $this->assertStringContainsString("wire:key=\"purchase-cart-item-{$rowId}\"", $html,
            'Cart row must have stable wire:key to prevent DOM reuse');
        $this->assertStringContainsString("wire:key=\"purchase-line-total-input-{$rowId}\"", $html,
            'Line-total input must have distinct wire:key for Alpine initialization');

        // Verify the Alpine :value directive includes exactly 46500
        $this->assertStringContainsString(":value=\"open ? '46500'", $html,
            'Alpine :value directive must initialize input with full canonical sub_total (46500)');
        $this->assertStringNotContainsString(":value=\"open ? '4650'", $html,
            'Truncated 4650 value must NOT appear in :value directive');

        // Verify component state holds the expected value
        $component->assertViewHas('cart_items', function ($items) {
            $item = $items->first();
            $this->assertEquals(46500, $item->options->sub_total);
            return true;
        });
    }

    public function test_purchase_edit_cart_hydration_46500_line_total_with_stable_keys()
    {
        // Regression: edit-cart hydration with explicit 46500 line total must preserve
        // the full value, not truncate it. Verify stable wire:key attributes exist
        // to prevent Livewire from reusing stale DOM elements.
        Cart::instance('purchase')->destroy();

        // Manually add a cart item with explicit 46500 subtotal (not calculated)
        Cart::instance('purchase')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 41892,
            'weight' => 1,
            'options' => [
                'product_discount' => 0,
                'product_discount_input' => 0,
                'product_discount_type' => 'fixed',
                'sub_total' => 46500,
                'sub_total_before_tax' => 41892,
                'product_tax_amount' => 4608,
                'code' => $this->product->product_code,
                'stock' => 100,
                'unit' => 'pc',
                'last_purchase_price' => 41892,
                'average_purchase_price' => 41892,
                'product_tax' => $this->tax11->id,
                'unit_price' => 41892,
            ],
        ]);

        $component = Livewire::test(ProductCart::class, [
            'cartInstance' => 'purchase',
            'selectedSettingId' => $this->setting->id,
        ]);

        $cartItem = Cart::instance('purchase')->content()->first();
        $rowId = $cartItem->rowId;

        // Verify the rendered HTML contains the full 46500 value in Alpine :value directive
        $html = $component->html();
        $this->assertStringContainsString(":value=\"open ? '46500'", $html,
            'Alpine :value directive must initialize input with full canonical sub_total (46500)');
        $this->assertStringNotContainsString(":value=\"open ? '4650'", $html,
            'Truncated 4650 value must NOT appear in :value directive');

        // Verify stable wire:key attributes
        $this->assertStringContainsString("wire:key=\"purchase-cart-item-{$rowId}\"", $html);
        $this->assertStringContainsString("wire:key=\"purchase-line-total-input-{$rowId}\"", $html);

        // Verify component state matches the expected 46500
        $component->assertViewHas('cart_items', function ($items) {
            $item = $items->first();
            $this->assertEquals(46500, $item->options->sub_total,
                'Cart item sub_total must equal exactly 46500');
            return true;
        });
    }

    public function test_purchase_committed_replacement_line_total_follows_validation_rules()
    {
        // Verify that committing a replacement Total Baris (50000) recalculates correctly
        // using reverse-calculation rules, following the existing validation and tax logic.
        Cart::instance('purchase')->destroy();

        $component = Livewire::test(ProductCart::class, [
            'cartInstance' => 'purchase',
            'selectedSettingId' => $this->setting->id,
        ])
            ->set('setting_id', $this->setting->id)
            ->set('isPkp', true)
            ->set('is_tax_included', false)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => $this->product->product_quantity,
                'product_unit' => 'pc',
                'last_purchase_price' => 41892,
                'average_purchase_price' => 41892,
            ])
            ->set('product_tax.' . $this->product->id, $this->tax11->id)
            ->call('updateTax', Cart::instance('purchase')->content()->first()->rowId, $this->product->id, $this->tax11->id);

        $cartItem = Cart::instance('purchase')->content()->first();
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
    }

    public function test_purchase_cart_hydration_46500_line_total_from_persisted_detail()
    {
        // Structural cart-hydration test: when cart is seeded from persisted Purchase and PurchaseDetail
        // with explicit 46500 line total, the rendered ProductCart must preserve the full value
        // in the Alpine :value directive without truncation or loss of precision.
        //
        // NOTE: This is a cart-hydration structural test, not a full edit-form integration test.
        // It manually seeds Cart::instance() and mounts ProductCart directly, simulating the
        // cart restoration step that occurs in EditForm.mount() and EditForm.restoreCart().
        // A real edit-form integration test (mounting Purchase\EditForm → triggering restoreCart()
        // → inspecting child ProductCart) is a separate concern and documented in OpenSpec.
        Cart::instance('purchase')->destroy();

        $supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'supplier@test.com',
            'supplier_phone' => '123456789',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Test Address',
            'setting_id' => $this->setting->id,
        ]);

        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-TEST-' . uniqid(),
            'supplier_id' => $supplier->id,
            'status' => Purchase::STATUS_DRAFTED,
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 46500,
            'paid_amount' => 0,
            'due_amount' => 46500,
            'setting_id' => $this->setting->id,
        ]);

        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 41892,
            'unit_price' => 41892,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'sub_total' => 46500,
            'product_tax_amount' => 4608,
            'tax_id' => $this->tax11->id,
        ]);

        // Simulate the cart restoration that happens in EditForm.mount() → EditForm.restoreCart()
        Cart::instance('purchase')->add([
            'id' => $purchaseDetail->product_id,
            'name' => $purchaseDetail->product_name,
            'qty' => $purchaseDetail->quantity,
            'price' => $purchaseDetail->price,
            'weight' => 1,
            'options' => [
                'product_discount' => $purchaseDetail->product_discount_amount,
                'product_discount_input' => 0,
                'product_discount_type' => $purchaseDetail->product_discount_type,
                'sub_total' => $purchaseDetail->sub_total,
                'code' => $purchaseDetail->product_code,
                'stock' => $this->product->product_quantity,
                'product_tax' => $this->tax11->id,
                'unit_price' => $purchaseDetail->unit_price,
                'sub_total_before_tax' => 41892,
                'product_tax_amount' => $purchaseDetail->product_tax_amount,
            ],
        ]);

        $component = Livewire::test(ProductCart::class, [
            'cartInstance' => 'purchase',
            'selectedSettingId' => $this->setting->id,
        ]);

        $cartItem = Cart::instance('purchase')->content()->first();
        $rowId = $cartItem->rowId;

        // Verify the rendered HTML contains exactly 46500 in the Alpine :value directive
        $html = $component->html();
        $this->assertStringContainsString(":value=\"open ? '46500'", $html,
            'Alpine :value directive must initialize input with full line total (46500), not truncated');
        $this->assertStringNotContainsString(":value=\"open ? '4650'", $html,
            'Truncated 4650 value must NOT appear in :value directive');

        // Verify stable wire:key attributes
        $this->assertStringContainsString("wire:key=\"purchase-cart-item-{$rowId}\"", $html);
        $this->assertStringContainsString("wire:key=\"purchase-line-total-input-{$rowId}\"", $html);

        // Verify component state matches the persisted 46500
        $component->assertViewHas('cart_items', function ($items) {
            $item = $items->first();
            $this->assertEquals(46500, $item->options->sub_total,
                'Cart item sub_total must equal persisted value (46500)');
            return true;
        });
    }
}
