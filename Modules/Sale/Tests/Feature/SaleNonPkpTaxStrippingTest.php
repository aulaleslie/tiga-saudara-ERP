<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SaleNonPkpTaxStrippingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Config::set('scout.driver', null);
    }

    public function test_sale_store_strips_tax_when_non_pkp()
    {
        \Modules\Currency\Entities\Currency::create([
             'id' => 1,
             'currency_name' => 'Rupiah',
             'code' => 'IDR',
             'symbol' => 'Rp',
             'thousand_separator' => '.',
             'decimal_separator' => ',',
             'exchange_rate' => 1,
        ]);
        
        $setting = Setting::create([
            'id' => 1,
            'company_name' => 'Non PKP Copmany',
            'company_email' => 'test@company.com',
            'company_phone' => '1234567890',
            'company_address' => 'Test Address',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notification@test.com',
            'footer_text' => 'Test Footer',
            'is_pkp' => false,
        ]);

        $tax = Tax::create(['name' => 'PPN', 'value' => 11]);

        $customer = Customer::create([
            'customer_name' => 'Test Customer',
            'customer_email' => 'test@customer.com',
            'customer_phone' => '1111',
            'city' => 'Test',
            'country' => 'Test',
            'address' => 'Test Address',
            'setting_id' => $setting->id,
        ]);

        $product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'P001',
            'product_quantity' => 10,
            'product_cost' => 1000,
            'product_price' => 1500,
            'product_unit' => 'pcs',
            'product_stock_alert' => 5,
            'setting_id' => $setting->id,
        ]);

        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'sales.create']);
        $user->givePermissionTo('sales.create');

        // Add to sale cart via Livewire equivalent
        \Gloudemans\Shoppingcart\Facades\Cart::instance('sale')->add([
            'id' => $product->id,
            'name' => $product->product_name,
            'qty' => 1,
            'price' => 1500,
            'weight' => 1,
            'options' => [
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'sub_total' => 1500,
                'code' => $product->product_code,
                'stock' => 10,
                'product_tax' => $tax->id,
                'unit_price' => 1500,
                'product_id' => $product->id,
                'product_tax_amount' => 165,
                'sub_total_before_tax' => 1500
            ]
        ]);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('sales.store'), [
                'date' => now()->toDateString(),
                'due_date' => now()->addDays(7)->toDateString(),
                'reference' => 'SO-001',
                'customer_id' => $customer->id,
                'status' => 'Pending',
                'discount_percentage' => 0,
                'discount_amount' => 0,
                'shipping_amount' => 0,
                'total_amount' => 1500,
                // Sales store usually reads from Cart directly
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('sales.index'));

        $this->assertDatabaseHas('sales', [
            'total_amount' => 1500,
            'tax_amount' => 0, // Enforced zero
            'tax_id' => null, // Enforced null
        ]);

        $this->assertDatabaseHas('sale_details', [
            'product_id' => $product->id,
            'tax_id' => null, // Stripped
            'product_tax_amount' => 0, // Zeroed
        ]);
    }
}
