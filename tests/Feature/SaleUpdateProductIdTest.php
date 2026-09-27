<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tests for SF0-BE-01: Fix SaleController update product_id + tax_id
 */
class SaleUpdateProductIdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function createSetting(): Setting
    {
        return Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'company@example.com',
            'company_phone' => '1234567890',
            'site_logo' => null,
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => '123 Testing Lane',
            'document_prefix' => null,
            'purchase_prefix_document' => null,
            'sale_prefix_document' => null,
        ]);
    }

    private function createPaymentTerm(): PaymentTerm
    {
        return PaymentTerm::create([
            'name' => 'Net 30',
            'longevity' => 30,
        ]);
    }

    private function createCustomer(Setting $setting, PaymentTerm $paymentTerm): Customer
    {
        return Customer::factory()->create([
            'setting_id' => $setting->id,
            'payment_term_id' => $paymentTerm->id,
        ]);
    }

    private function createProduct(Setting $setting): Product
    {
        return Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TST-001',
            'setting_id' => $setting->id,
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 1500,
        ]);
    }

    private function createTax(): Tax
    {
        return Tax::create([
            'name' => 'PPN 11%',
            'value' => 11,
        ]);
    }

    /**
     * Test that product_id is correctly preserved after update.
     * Previously, update() was using $cart_item->id (UUID) instead of $cart_item->options->product_id
     */
    public function test_product_id_preserved_after_update(): void
    {
        $this->withoutMiddleware(CheckUserRoleForSetting::class);

        $setting = $this->createSetting();
        $paymentTerm = $this->createPaymentTerm();
        $customer = $this->createCustomer($setting, $paymentTerm);
        $product = $this->createProduct($setting);

        Permission::firstOrCreate(['name' => 'sales.edit']);

        $user = User::factory()->create();
        $user->givePermissionTo('sales.edit');

        // Create a sale with a sale detail
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1500,
            'paid_amount' => 0,
            'due_amount' => 1500,
            'status' => 'Drafted',
            'payment_status' => 'Unpaid',
            'payment_method' => 'cash',
            'note' => null,
            'payment_term_id' => $paymentTerm->id,
            'tax_id' => null,
            'setting_id' => $setting->id,
            'is_tax_included' => false,
        ]);

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1500,
            'unit_price' => 1500,
            'sub_total' => 1500,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        // Simulate cart setup as done in edit() method
        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => Str::uuid()->toString(), // This is the bug - update() was using this as product_id
            'name' => $product->product_name,
            'qty' => 2, // Changed quantity
            'price' => 1500,
            'weight' => 1,
            'options' => [
                'product_id' => $product->id, // Correct product_id should come from here
                'code' => $product->product_code,
                'unit_price' => 1500,
                'sub_total' => 3000,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => null,
                'bundle_items' => [],
            ],
        ]);

        // Execute update
        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->put(route('sales.update', $sale), [
                'customer_id' => $customer->id,
                'reference' => $sale->reference,
                'date' => now()->toDateString(),
                'tax_percentage' => 0,
                'discount_percentage' => 0,
                'shipping_amount' => 0,
                'total_amount' => 3000,
                'paid_amount' => 0,
                'status' => 'Drafted',
                'payment_method' => 'cash',
                'note' => 'Updated note',
            ]);

        // Refresh sale from database
        $sale->refresh();
        $updatedDetail = $sale->saleDetails->first();

        // Assert product_id is a valid integer (actual product ID), not a UUID string
        $this->assertNotNull($updatedDetail);
        $this->assertIsInt($updatedDetail->product_id);
        $this->assertEquals($product->id, $updatedDetail->product_id);
        $this->assertEquals(2, $updatedDetail->quantity);
    }

    /**
     * Test that tax_id is correctly preserved after update.
     * Previously, update() was not saving tax_id at all.
     */
    public function test_tax_id_preserved_after_update(): void
    {
        $this->withoutMiddleware(CheckUserRoleForSetting::class);

        $setting = $this->createSetting();
        $paymentTerm = $this->createPaymentTerm();
        $customer = $this->createCustomer($setting, $paymentTerm);
        $product = $this->createProduct($setting);
        $tax = $this->createTax();

        Permission::firstOrCreate(['name' => 'sales.edit']);

        $user = User::factory()->create();
        $user->givePermissionTo('sales.edit');

        // Create a sale with a sale detail that has a tax
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 165, // 11% of 1500
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1665,
            'paid_amount' => 0,
            'due_amount' => 1665,
            'status' => 'Drafted',
            'payment_status' => 'Unpaid',
            'payment_method' => 'cash',
            'note' => null,
            'payment_term_id' => $paymentTerm->id,
            'tax_id' => null,
            'setting_id' => $setting->id,
            'is_tax_included' => false,
        ]);

        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1500,
            'unit_price' => 1500,
            'sub_total' => 1665,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 165,
            'tax_id' => $tax->id,
        ]);

        // Simulate cart setup as done in edit() method
        Cart::instance('sale')->destroy();
        Cart::instance('sale')->add([
            'id' => Str::uuid()->toString(),
            'name' => $product->product_name,
            'qty' => 1,
            'price' => 1500,
            'weight' => 1,
            'options' => [
                'product_id' => $product->id,
                'code' => $product->product_code,
                'unit_price' => 1500,
                'sub_total' => 1665,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'product_tax' => $tax->id, // This is where tax_id comes from
                'bundle_items' => [],
            ],
        ]);

        // Execute update
        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->put(route('sales.update', $sale), [
                'customer_id' => $customer->id,
                'reference' => $sale->reference,
                'date' => now()->toDateString(),
                'tax_percentage' => 0,
                'discount_percentage' => 0,
                'shipping_amount' => 0,
                'total_amount' => 1665,
                'paid_amount' => 0,
                'status' => 'Drafted',
                'payment_method' => 'cash',
                'note' => 'Updated with tax',
            ]);

        // Refresh sale from database
        $sale->refresh();
        $updatedDetail = $sale->saleDetails->first();

        // Assert tax_id is preserved
        $this->assertNotNull($updatedDetail);
        $this->assertEquals($tax->id, $updatedDetail->tax_id);
    }
}
