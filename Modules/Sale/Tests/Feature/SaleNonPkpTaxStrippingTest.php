<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SaleNonPkpTaxStrippingTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Tax $tax;
    private Customer $customer;
    private Product $product;
    private PaymentTerm $paymentTerm;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Config::set('scout.driver', null);

        \Modules\Currency\Entities\Currency::create([
             'id' => 1,
             'currency_name' => 'Rupiah',
             'code' => 'IDR',
             'symbol' => 'Rp',
             'thousand_separator' => '.',
             'decimal_separator' => ',',
             'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
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

        $this->tax = Tax::create(['name' => 'PPN', 'value' => 11]);
        $this->paymentTerm = PaymentTerm::query()->firstOrCreate(
            ['name' => 'Cash on Delivery'],
            ['longevity' => 0]
        );

        $this->customer = Customer::create([
            'customer_name' => 'Test Customer',
            'customer_email' => 'test@customer.com',
            'customer_phone' => '1111',
            'city' => 'Test',
            'country' => 'Test',
            'address' => 'Test Address',
            'setting_id' => $this->setting->id,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        $this->product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'P001',
            'product_quantity' => 10,
            'product_cost' => 1000,
            'product_price' => 1500,
            'product_unit' => 'pcs',
            'product_stock_alert' => 5,
            'setting_id' => $this->setting->id,
        ]);

        $this->user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'sales.create']);
        Permission::firstOrCreate(['name' => 'sales.edit']);
        $this->user->givePermissionTo('sales.create');
        $this->user->givePermissionTo('sales.edit');

        Cart::instance('sale')->destroy();
    }

    protected function tearDown(): void
    {
        Cart::instance('sale')->destroy();

        parent::tearDown();
    }

    public function test_sale_store_normalizes_hidden_tax_when_non_pkp()
    {
        Cart::instance('sale')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 1110,
            'weight' => 1,
            'options' => [
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'sub_total' => 1110,
                'code' => $this->product->product_code,
                'stock' => 10,
                'product_tax' => $this->tax->id,
                'unit_price' => 1110,
                'product_id' => $this->product->id,
                'product_tax_amount' => 110,
                'sub_total_before_tax' => 1000,
            ]
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->post(route('sales.store'), [
                'date' => now()->toDateString(),
                'due_date' => now()->addDays(7)->toDateString(),
                'reference' => 'SO-001',
                'customer_id' => $this->customer->id,
                'status' => 'Pending',
                'discount_percentage' => 0,
                'discount_amount' => 0,
                'shipping_amount' => 0,
                'total_amount' => 1110,
                'payment_term_id' => $this->paymentTerm->id,
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('sales.index'));

        $this->assertDatabaseHas('sales', [
            'total_amount' => 1000,
            'due_amount' => 1000,
            'tax_amount' => 0,
            'tax_id' => null,
        ]);

        $this->assertDatabaseHas('sale_details', [
            'product_id' => $this->product->id,
            'tax_id' => null,
            'product_tax_amount' => 0,
            'sub_total' => 1000,
        ]);
    }

    public function test_sale_update_normalizes_hidden_tax_when_non_pkp(): void
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'reference' => 'SO-UPDATE-001',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'tax_amount' => 110,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1110,
            'paid_amount' => 0,
            'due_amount' => 1110,
            'status' => Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 1110,
            'unit_price' => 1110,
            'sub_total' => 1110,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 110,
            'tax_id' => $this->tax->id,
        ]);

        Cart::instance('sale')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 1110,
            'weight' => 1,
            'options' => [
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'sub_total' => 1110,
                'code' => $this->product->product_code,
                'stock' => 10,
                'product_tax' => $this->tax->id,
                'unit_price' => 1110,
                'product_id' => $this->product->id,
                'product_tax_amount' => 110,
                'sub_total_before_tax' => 1000,
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['setting_id' => $this->setting->id])
            ->put(route('sales.update', $sale), [
                'customer_id' => $this->customer->id,
                'date' => now()->toDateString(),
                'reference' => $sale->reference,
                'tax_percentage' => 11,
                'discount_percentage' => 0,
                'shipping_amount' => 0,
                'total_amount' => 1110,
                'paid_amount' => 0,
                'status' => Sale::STATUS_DRAFTED,
                'payment_method' => 'Cash',
                'note' => null,
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('sales.index'));

        $sale->refresh();
        $detail = SaleDetails::query()->where('sale_id', $sale->id)->firstOrFail();

        $this->assertSame(0.0, (float) $sale->tax_amount);
        $this->assertSame(1000.0, (float) $sale->total_amount);
        $this->assertSame(1000.0, (float) $sale->due_amount);
        $this->assertNull($detail->tax_id);
        $this->assertSame(0.0, (float) $detail->product_tax_amount);
        $this->assertSame(1000.0, (float) $detail->sub_total);
    }
}
