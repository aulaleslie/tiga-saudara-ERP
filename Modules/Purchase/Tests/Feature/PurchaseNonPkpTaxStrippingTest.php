<?php

namespace Modules\Purchase\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PurchaseNonPkpTaxStrippingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Config::set('scout.driver', null);
    }

    public function test_purchase_store_strips_tax_when_non_pkp()
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
        
        // Setup Setting as non-PKP
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

        $supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@supplier.com',
            'supplier_phone' => '1111',
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
        Permission::firstOrCreate(['name' => 'purchases.create']);
        $user->givePermissionTo('purchases.create');

        $cartData = [
            [
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_price' => 1000,
                'discount_type' => 'fixed',
                'discount' => 0,
                'tax_id' => $tax->id, // Passing tax_id from frontend
            ]
        ];

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('purchases.store'), [
                'date' => now()->toDateString(),
                'due_date' => now()->addDays(7)->toDateString(),
                'reference' => 'PO-001',
                'supplier_id' => $supplier->id,
                'status' => 'Pending',
                'discount_percentage' => 0,
                'discount_amount' => 0,
                'shipping_amount' => 0,
                'total_amount' => 2220,
                'cart' => $cartData,
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('purchases.index'));

        $this->assertDatabaseHas('purchases', [
            'total_amount' => 2000,
            'tax_amount' => 0, // Enforced zero
            'tax_id' => null, // Enforced null
        ]);

        $this->assertDatabaseHas('purchase_details', [
            'product_id' => $product->id,
            'tax_id' => null, // Stripped
            'product_tax_amount' => 0, // Zeroed
        ]);
    }
}
