<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Purchase\ProductCart;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Purchase\Entities\Purchase;
use Tests\TestCase;

class PurchaseProductCartDiscountTaxTest extends TestCase
{
    use RefreshDatabase;

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

        $setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
            'is_pkp' => true,
        ]);

        session(['setting_id' => $setting->id]);

        $this->tax = Tax::create([
            'name' => 'PPN 11',
            'value' => 11,
            'is_default' => true,
        ]);

        $this->product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'product_quantity' => 100,
            'setting_id' => $setting->id,
            'product_cost' => 10000,
            'product_price' => 11100, // Changed to 11100 for easier math with 11% tax included
            'product_unit' => 'pcs',
        ]);

        Cart::instance('purchase')->destroy();
    }

    public function test_discount_off_tax_inclusive_price_option_a(): void
    {
        Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('setting_id', session('setting_id'))
            ->set('isPkp', true)
            ->set('is_tax_included', true)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => $this->product->product_quantity,
                'product_unit' => $this->product->product_unit,
                'last_purchase_price' => 11100,
                'average_purchase_price' => 11100,
                'purchase_tax_id' => $this->tax->id,
            ])
            ->set('product_tax.' . $this->product->id, $this->tax->id)
            ->set('discount_type.' . $this->product->id, 'fixed')
            ->set('item_discount.' . $this->product->id, 1110)
            ->call('setProductDiscount', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        
        $this->assertEquals(9990.0, (float) $cartItem->options->sub_total);
        $this->assertEquals(9000.0, (float) $cartItem->options->sub_total_before_tax);
        $this->assertEquals(990.0, (float) $cartItem->options->product_tax_amount);
    }

    public function test_percentage_discount_with_tax_included(): void
    {
        Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('setting_id', session('setting_id'))
            ->set('isPkp', true)
            ->set('is_tax_included', true)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => $this->product->product_quantity,
                'product_unit' => $this->product->product_unit,
                'last_purchase_price' => 11100,
                'average_purchase_price' => 11100,
                'purchase_tax_id' => $this->tax->id,
            ])
            ->set('product_tax.' . $this->product->id, $this->tax->id)
            ->set('discount_type.' . $this->product->id, 'percentage')
            ->set('item_discount.' . $this->product->id, 10)
            ->call('setProductDiscount', Cart::instance('purchase')->content()->first()->rowId, $this->product->id);

        $cartItem = Cart::instance('purchase')->content()->first();
        
        $this->assertEquals(9990.0, (float) $cartItem->options->sub_total);
        $this->assertEquals(9000.0, (float) $cartItem->options->sub_total_before_tax);
        $this->assertEquals(990.0, (float) $cartItem->options->product_tax_amount);
    }

    public function test_global_discount_applied_on_total_after_tax(): void
    {
        Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('setting_id', session('setting_id'))
            ->set('isPkp', true)
            ->set('is_tax_included', false)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => $this->product->product_quantity,
                'product_unit' => $this->product->product_unit,
                'last_purchase_price' => 10000,
                'average_purchase_price' => 10000,
                'purchase_tax_id' => null,
            ])
            ->set('global_discount_type', 'percentage')
            ->set('global_discount', 10)
            ->assertViewHas('grand_total', 9990.0);
    }

    public function test_global_discount_with_tax_included(): void
    {
        Livewire::test(ProductCart::class, ['cartInstance' => 'purchase'])
            ->set('setting_id', session('setting_id'))
            ->set('isPkp', true)
            ->set('is_tax_included', true)
            ->call('productSelected', [
                'id' => $this->product->id,
                'product_name' => $this->product->product_name,
                'product_code' => $this->product->product_code,
                'product_quantity' => $this->product->product_quantity,
                'product_unit' => $this->product->product_unit,
                'last_purchase_price' => 11100, // This is already tax inclusive
                'average_purchase_price' => 11100,
                'purchase_tax_id' => $this->tax->id,
            ])
            ->set('product_tax.' . $this->product->id, $this->tax->id)
            ->set('global_discount_type', 'percentage')
            ->set('global_discount', 10)
            ->assertViewHas('grand_total', 9990.0);
    }

    public function test_purchase_model_casts_is_tax_included(): void
    {
        $setting = Setting::first();
        $supplier = \Modules\People\Entities\Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'supplier@example.com',
            'supplier_phone' => '123456789',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
            'setting_id' => $setting->id,
        ]);
        
        $purchase = Purchase::create([
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->addDays(7)->format('Y-m-d'),
            'reference' => 'PUR-001-' . uniqid(),
            'supplier_id' => $supplier->id,
            'status' => 'Pending',
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'is_tax_included' => true,
            'setting_id' => $setting->id,
        ]);

        $this->assertTrue($purchase->is_tax_included);
        
        $freshPurchase = Purchase::find($purchase->id);
        $this->assertTrue($freshPurchase->is_tax_included);

        $purchase->update(['is_tax_included' => false]);
        $this->assertFalse($purchase->fresh()->is_tax_included);
    }
}
