<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Purchase\ProductCart;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Currency\Entities\Currency;
use Tests\TestCase;

class PurchaseProductCartLineTotalTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;
    protected Setting $pkpSetting;
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
            'company_name' => 'Non PKP Setting',
            'company_email' => 'non-pkp@example.com',
            'company_phone' => '123456789',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => false,
        ]);

        $this->pkpSetting = Setting::create([
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

        $this->product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST-01',
            'product_quantity' => 10,
            'setting_id' => $this->setting->id,
            'product_cost' => 1000,
            'product_price' => 1000,
            'product_unit' => 'pcs',
        ]);

        $this->tax11 = Tax::create([
            'name' => 'PPN 11%',
            'value' => 11,
            'is_default' => true,
        ]);

        Cart::instance('purchase')->destroy();
    }

    public function test_reverse_calculate_line_total_non_pkp_no_discount()
    {
        $livewire = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('setting_id', $this->setting->id)
            ->set('isPkp', false)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 10,
                'product_unit' => 'pcs',
                'last_purchase_price' => 1000,
                'average_purchase_price' => 1000,
            ])
            ->set('quantity.' . $this->product->id, 2)
            ->set('quantity.' . $this->product->id, 2)
            ->call('updateQuantity', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $livewire->set('line_total.' . $this->product->id, 2500)
            ->call('updateLineTotal', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        $this->assertEquals(1250, $cartItem->price);
        $this->assertEquals(2500, $cartItem->options->sub_total);
    }

    public function test_reverse_calculate_line_total_pkp_tax_excluded()
    {
        $livewire = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('setting_id', $this->pkpSetting->id)
            ->set('isPkp', true)
            ->set('is_tax_included', false)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 10,
                'product_unit' => 'pcs',
                'last_purchase_price' => 1000,
                'average_purchase_price' => 1000,
            ])
            ->set('quantity.' . $this->product->id, 2)
            ->set('quantity.' . $this->product->id, 2)
            ->call('updateQuantity', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $livewire->set('product_tax.' . $this->product->id, $this->tax11->id)
            ->call('updateTax', Cart::instance('purchase')->content()->first()->rowId, $this->product->id, $this->tax11->id);

        $livewire->set('line_total.' . $this->product->id, 3330) // base: 3000, qty 2 -> unit price 1500
            ->call('updateLineTotal', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        // (3330 / 2) / 1.11 = 1500
        $this->assertEquals(1500, $cartItem->price);
        $this->assertEquals(3330, $cartItem->options->sub_total);
    }

    public function test_reverse_calculate_line_total_pkp_tax_included()
    {
        $livewire = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('setting_id', $this->pkpSetting->id)
            ->set('isPkp', true)
            ->set('is_tax_included', true)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 10,
                'product_unit' => 'pcs',
                'last_purchase_price' => 1000,
                'average_purchase_price' => 1000,
            ])
            ->set('quantity.' . $this->product->id, 2)
            ->set('quantity.' . $this->product->id, 2)
            ->call('updateQuantity', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $livewire->set('product_tax.' . $this->product->id, $this->tax11->id)
            ->call('updateTax', Cart::instance('purchase')->content()->first()->rowId, $this->product->id, $this->tax11->id);

        $livewire->set('line_total.' . $this->product->id, 3000) // tax included, so effective price = 1500, qty=2 -> 3000
            ->call('updateLineTotal', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        // (3000 / 2) = 1500
        $this->assertEquals(1500, $cartItem->price);
        $this->assertEquals(3000, $cartItem->options->sub_total);
    }

    public function test_reverse_calculate_line_total_with_fixed_discount()
    {
        $livewire = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('setting_id', $this->setting->id)
            ->set('isPkp', false)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 10,
                'product_unit' => 'pcs',
                'last_purchase_price' => 1000,
                'average_purchase_price' => 1000,
            ])
            ->set('quantity.' . $this->product->id, 2)
            ->set('quantity.' . $this->product->id, 2)
            ->call('updateQuantity', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $livewire->set('discount_type.' . $this->product->id, 'fixed')
            ->set('item_discount.' . $this->product->id, 200) // per unit discount
            ->call('setProductDiscount', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        // original effective price = (1000 - 200) * 2 = 1600. Let's change line total to 2000.
        // effective price per unit = 1000. So base price = 1000 + 200 = 1200.
        $livewire->set('line_total.' . $this->product->id, 2000)
            ->call('updateLineTotal', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);
            
        $cartItem = Cart::instance('purchase')->content()->first();
        $this->assertEquals(1200, $cartItem->price);
        $this->assertEquals(200, $cartItem->options->product_discount);
        $this->assertEquals(2000, $cartItem->options->sub_total);
    }

    public function test_reverse_calculate_line_total_with_percentage_discount()
    {
        $livewire = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('setting_id', $this->setting->id)
            ->set('isPkp', false)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 10,
                'product_unit' => 'pcs',
                'last_purchase_price' => 1000,
                'average_purchase_price' => 1000,
            ])
            ->set('quantity.' . $this->product->id, 2)
            ->set('quantity.' . $this->product->id, 2)
            ->call('updateQuantity', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $livewire->set('discount_type.' . $this->product->id, 'percentage')
            ->set('item_discount.' . $this->product->id, 20) // 20%
            ->call('setProductDiscount', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        // original effective price = (1000 * 0.8) * 2 = 1600
        // Let's change line total to 2000.
        // effective price per unit = 1000. Base price = 1000 / (1 - 0.20) = 1250.
        $livewire->set('line_total.' . $this->product->id, 2000)
            ->call('updateLineTotal', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        $this->assertEquals(1250, $cartItem->price);
        $this->assertEquals(250, $cartItem->options->product_discount);
        $this->assertEquals(2000, $cartItem->options->sub_total);
    }

    public function test_rejects_invalid_line_total_input()
    {
        $livewire = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('setting_id', $this->setting->id)
            ->set('isPkp', false)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 10,
                'product_unit' => 'pcs',
                'last_purchase_price' => 1000,
                'average_purchase_price' => 1000,
            ])
            ->set('line_total.' . $this->product->id, -500)
            ->call('updateLineTotal', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        $this->assertEquals(1000, $cartItem->price);
        $this->assertEquals(1000, $cartItem->options->sub_total);
    }

    public function test_rejects_line_total_update_with_100_percent_discount()
    {
        $livewire = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('setting_id', $this->setting->id)
            ->set('isPkp', false)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 10,
                'product_unit' => 'pcs',
                'last_purchase_price' => 1000,
                'average_purchase_price' => 1000,
            ]);

        $livewire->set('discount_type.' . $this->product->id, 'percentage')
            ->set('item_discount.' . $this->product->id, 100)
            ->call('setProductDiscount', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        // Subtotal should be 0 now.
        $cartItem = Cart::instance('purchase')->content()->first();
        $this->assertEquals(0, $cartItem->options->sub_total);

        // Try to update line total to a nonzero value
        $livewire->set('line_total.' . $this->product->id, 500)
            ->call('updateLineTotal', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        $this->assertEquals(0, $cartItem->options->sub_total);
        $this->assertEquals(1000, $cartItem->price);
        $livewire->assertSee('Total baris lebih dari 0 tidak dimungkinkan dengan diskon 100%.');
    }

    public function test_non_pkp_non_divisible_line_total_is_preserved()
    {
        $this->product->update(['product_quantity' => 2000]);

        $livewire = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('setting_id', $this->setting->id)
            ->set('isPkp', false)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 2000,
                'product_unit' => 'pcs',
                'last_purchase_price' => 1000,
                'average_purchase_price' => 1000,
            ])
            ->set('quantity.' . $this->product->id, 1200)
            ->call('updateQuantity', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $livewire->set('line_total.' . $this->product->id, 1460000)
            ->call('updateLineTotal', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        $this->assertEquals(1460000, $cartItem->options->sub_total, 'Committed total must be preserved as authoritative');
        $this->assertEquals(1460000, $cartItem->options->sub_total_before_tax, 'No tax in non-PKP');
        $this->assertEquals(0, $cartItem->options->product_tax_amount, 'No tax in non-PKP');
        $this->assertEquals(1216.67, round($cartItem->price, 2), 'Derived unit price should be 1460000 / 1200 = 1216.67');
    }

    public function test_pkp_tax_included_non_divisible_total_is_preserved()
    {
        $this->product->update(['product_quantity' => 2000]);

        $livewire = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('setting_id', $this->pkpSetting->id)
            ->set('isPkp', true)
            ->set('is_tax_included', true)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 2000,
                'product_unit' => 'pcs',
                'last_purchase_price' => 1000,
                'average_purchase_price' => 1000,
            ])
            ->set('quantity.' . $this->product->id, 1200)
            ->call('updateQuantity', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $livewire->set('product_tax.' . $this->product->id, $this->tax11->id)
            ->call('updateTax', Cart::instance('purchase')->content()->first()->rowId, $this->product->id, $this->tax11->id);

        $livewire->set('line_total.' . $this->product->id, 1460000)
            ->call('updateLineTotal', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        $this->assertEquals(1460000.00, (float) $cartItem->options->sub_total, 'Committed total must be preserved as authoritative');
        $subtotalBeforeTax = (float) $cartItem->options->sub_total_before_tax;
        $tax = (float) $cartItem->options->product_tax_amount;
        $this->assertEquals(1460000.00, round($subtotalBeforeTax + $tax, 2), 'sub_total_before_tax + product_tax_amount must equal sub_total');
    }

    public function test_pkp_tax_exclusive_non_divisible_total_is_preserved()
    {
        $this->product->update(['product_quantity' => 2000]);

        $livewire = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('setting_id', $this->pkpSetting->id)
            ->set('isPkp', true)
            ->set('is_tax_included', false)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 2000,
                'product_unit' => 'pcs',
                'last_purchase_price' => 1000,
                'average_purchase_price' => 1000,
            ])
            ->set('quantity.' . $this->product->id, 1200)
            ->call('updateQuantity', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $livewire->set('product_tax.' . $this->product->id, $this->tax11->id)
            ->call('updateTax', Cart::instance('purchase')->content()->first()->rowId, $this->product->id, $this->tax11->id);

        $livewire->set('line_total.' . $this->product->id, 1460000)
            ->call('updateLineTotal', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        $this->assertEquals(1460000.00, (float) $cartItem->options->sub_total, 'Committed total must be preserved as authoritative');
        $subtotalBeforeTax = (float) $cartItem->options->sub_total_before_tax;
        $tax = (float) $cartItem->options->product_tax_amount;
        $this->assertEquals(1460000.00, round($subtotalBeforeTax + $tax, 2), 'sub_total_before_tax + product_tax_amount must equal sub_total');
    }

    public function test_committing_line_total_updates_options_entered_unit_price()
    {
        $this->product->update([
            'product_cost' => 1200000,
            'product_price' => 1200000,
        ]);

        $livewire = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('setting_id', $this->setting->id)
            ->set('isPkp', false)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 10,
                'product_unit' => 'pcs',
                'last_purchase_price' => 1200000,
                'average_purchase_price' => 1200000,
            ]);

        $rowId = Cart::instance('purchase')->content()->first()->rowId;

        $livewire->set("quantity.$rowId", 2)
            ->call('updateQuantity', $rowId, $this->product->id);

        $rowId = Cart::instance('purchase')->content()->first()->rowId;

        $livewire->set("line_total.$rowId", 2157350)
            ->call('updateLineTotal', $rowId, $this->product->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        $this->assertEquals(2157350, $cartItem->options->sub_total);
        $this->assertEquals(1078675, $cartItem->price);
        $this->assertEquals(1078675, $cartItem->options->unit_price);
        $this->assertEquals(1078675, $cartItem->options->entered_unit_price);
    }

    public function test_committing_line_total_with_fixed_discount_derives_correct_entered_unit_price()
    {
        $livewire = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('setting_id', $this->setting->id)
            ->set('isPkp', false)
            ->set('is_tax_included', true)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 10,
                'product_unit' => 'pcs',
                'last_purchase_price' => 1200000,
                'average_purchase_price' => 1200000,
            ]);

        $rowId = Cart::instance('purchase')->content()->first()->rowId;

        $livewire->set("quantity.$rowId", 2)
            ->call('updateQuantity', $rowId, $this->product->id);

        $rowId = Cart::instance('purchase')->content()->first()->rowId;

        $livewire->set("discount_type.$rowId", 'fixed')
            ->set("item_discount.$rowId", 50000)
            ->call('setProductDiscount', $rowId, $this->product->id);

        $rowId = Cart::instance('purchase')->content()->first()->rowId;

        $livewire->set("line_total.$rowId", 2000000)
            ->call('updateLineTotal', $rowId, $this->product->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        // effective_price = 2000000 / 2 = 1000000
        // base_price = 1000000 + 50000 = 1050000
        $this->assertEquals(2000000, $cartItem->options->sub_total);
        $this->assertEquals(1050000, $cartItem->price);
        $this->assertEquals(1050000, $cartItem->options->unit_price);
        $this->assertEquals(1050000, $cartItem->options->entered_unit_price);
    }

    public function test_committing_non_divisible_line_total_derives_correct_entered_unit_price_and_preserves_total()
    {
        $livewire = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('setting_id', $this->setting->id)
            ->set('isPkp', false)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => 10,
                'product_unit' => 'pcs',
                'last_purchase_price' => 1000,
                'average_purchase_price' => 1000,
            ]);

        $rowId = Cart::instance('purchase')->content()->first()->rowId;

        $livewire->set("quantity.$rowId", 3)
            ->call('updateQuantity', $rowId, $this->product->id);

        $rowId = Cart::instance('purchase')->content()->first()->rowId;

        $livewire->set("line_total.$rowId", 1000000)
            ->call('updateLineTotal', $rowId, $this->product->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        // 1000000 / 3 = 333333.33
        $this->assertEquals(1000000, $cartItem->options->sub_total);
        $this->assertEquals(333333.33, $cartItem->price);
        $this->assertEquals(333333.33, $cartItem->options->unit_price);
        $this->assertEquals(333333.33, $cartItem->options->entered_unit_price);
    }

    public function test_multiple_rows_of_same_product_isolate_line_total_and_discount_overrides()
    {
        Cart::instance('purchase')->destroy();

        // Line 1: 2 BOX @ 100.000 = 200.000
        $item1 = Cart::instance('purchase')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 2.0,
            'price' => 100000.0,
            'weight' => 1,
            'options' => [
                'code' => $this->product->product_code,
                'stock' => 100,
                'unit' => 'BOX',
                'purchase_unit_id' => 2,
                'product_unit_conversion_id' => 10,
                'conversion_factor' => 40.0,
                'unit_name' => 'BOX',
                'base_unit_name' => 'PCS',
                'entered_quantity' => 2.0,
                'entered_unit_price' => 100000.0,
                'unit_price' => 100000.0,
                'canonical_unit_price' => 2500.0,
                'sub_total' => 200000.0,
                'sub_total_before_tax' => 200000.0,
                'product_tax_amount' => 0.0,
                'product_discount' => 0.0,
                'product_discount_input' => 0.0,
                'product_discount_type' => 'fixed',
            ],
        ]);

        // Line 2: 5 PCS @ 2.500 = 12.500
        $item2 = Cart::instance('purchase')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 5.0,
            'price' => 2500.0,
            'weight' => 1,
            'options' => [
                'code' => $this->product->product_code,
                'stock' => 100,
                'unit' => 'PCS',
                'purchase_unit_id' => 1,
                'product_unit_conversion_id' => null,
                'conversion_factor' => 1.0,
                'unit_name' => 'PCS',
                'base_unit_name' => 'PCS',
                'entered_quantity' => 5.0,
                'entered_unit_price' => 2500.0,
                'unit_price' => 2500.0,
                'canonical_unit_price' => 2500.0,
                'sub_total' => 12500.0,
                'sub_total_before_tax' => 12500.0,
                'product_tax_amount' => 0.0,
                'product_discount' => 0.0,
                'product_discount_input' => 0.0,
                'product_discount_type' => 'fixed',
            ],
        ]);

        $this->assertNotEquals($item1->rowId, $item2->rowId);

        $livewire = Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('setting_id', $this->setting->id)
            ->set('isPkp', false);

        $rowId1 = $item1->rowId;
        $rowId2 = $item2->rowId;

        // Override total on Line 1 to 250.000 (qty 2 -> derived entered unit price 125.000)
        $livewire->set("line_total.$rowId1", 250000)
            ->call('updateLineTotal', $rowId1, $this->product->id);

        $updatedContent = Cart::instance('purchase')->content();
        $updatedRow1 = $updatedContent->first(fn ($item) => (int) ($item->options->purchase_unit_id ?? null) === 2);
        $updatedRow2 = $updatedContent->first(fn ($item) => (int) ($item->options->purchase_unit_id ?? null) === 1);

        $this->assertNotNull($updatedRow1);
        $this->assertNotNull($updatedRow2);

        // Line 1 should have updated total and derived entered unit price
        $this->assertEquals(250000, $updatedRow1->options->sub_total);
        $this->assertEquals(125000, $updatedRow1->options->entered_unit_price);
        $this->assertEquals(125000, $updatedRow1->price);

        // Line 2 must remain completely unaffected by Line 1's override
        $this->assertEquals(12500, $updatedRow2->options->sub_total);
        $this->assertEquals(2500, $updatedRow2->options->entered_unit_price);
        $this->assertEquals(2500, $updatedRow2->price);
        $this->assertEquals(0, $updatedRow2->options->product_discount);
        $this->assertEquals(0, $updatedRow2->options->product_tax_amount);
    }
}
