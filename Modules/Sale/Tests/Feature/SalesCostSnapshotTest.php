<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Modules\Setting\Entities\Location;
use Modules\Pos\Entities\PosCheckout;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Modules\People\Entities\Customer;

class SalesCostSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settingA = Setting::create([
            'company_name' => 'Setting A',
            'company_email' => 'a@test.com',
            'company_phone' => '1',
            'notification_email' => 'a@test.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        $this->location = Location::create([
            'setting_id' => $this->settingA->id,
            'name' => 'Gudang A'
        ]);

        $this->user = User::factory()->create();
        Permission::findOrCreate('sales.create', 'web');
        Permission::findOrCreate('sales.edit', 'web');
        Permission::findOrCreate('pos.sell', 'web');
        Permission::findOrCreate('sales.import', 'web');
        $this->user->givePermissionTo(['sales.create', 'sales.edit', 'pos.sell', 'sales.import']);
        $this->actingAs($this->user);
        session(['setting_id' => $this->settingA->id]);

        $this->customer = Customer::create([
            'customer_name' => 'Test Customer',
            'customer_email' => 'cust@test.com',
            'customer_phone' => '1234',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->settingA->id,
        ]);

        Unit::create([
            'id' => 1,
            'operator' => '*',
            'operation_value' => 1,
            'short_name' => 'pc',
            'name' => 'Piece',
            'setting_id' => $this->settingA->id,
        ]);

        $this->product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST-01',
            'product_barcode_symbology' => 'C128',
            'product_quantity' => 100, // Enough global stock
            'product_cost' => 0,
            'product_price' => 0,
            'product_stock_alert' => 0,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
            'product_note' => '',
            'unit_id' => 1,
            'setting_id' => $this->settingA->id,
            'stock_managed' => true,
        ]);
        
        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => 100,
            'quantity_non_tax' => 100,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        // Mock average purchase price = 500
        ProductPrice::create([
            'product_id' => $this->product->id,
            'setting_id' => $this->settingA->id,
            'sale_price' => 1000,
            'last_purchase_price' => 500,
            'average_purchase_price' => 500,
        ]);
        
        \Modules\Currency\Entities\Currency::create([
            'id' => 1,
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);
    }

    public function test_web_sale_creation_populates_cost_snapshot()
    {
        \Gloudemans\Shoppingcart\Facades\Cart::instance('sale')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 2,
            'price' => 1000,
            'weight' => 0,
            'options' => [
                'product_id' => $this->product->id,
                'product_code' => $this->product->product_code,
                'stock_managed' => true,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'sub_total' => 2000,
                'product_tax' => 0,
                'tax_id' => null,
                'unit_price' => 1000,
            ]
        ]);

        $response = $this->post(route('sales.store'), [
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'customer_id' => $this->customer->id,
            'reference' => 'TEST-001',
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 2000,
            'paid_amount' => 0,
            'status' => 'Pending',
            'payment_method' => 'Cash',
        ]);
        
        $response->assertSessionHasNoErrors();
        
        $detail = SaleDetails::first();
        $this->assertNotNull($detail);
        $this->assertEquals(500, $detail->cost_unit_snapshot);
        $this->assertEquals(1000, $detail->cost_total_snapshot); // 500 * 2
        $this->assertEquals('CURRENT_AVERAGE_PRICE', $detail->cost_snapshot_source);
        $this->assertNotNull($detail->cost_snapshot_at);
    }

    public function test_web_sale_update_populates_cost_snapshot()
    {
        // First create a sale
        \Gloudemans\Shoppingcart\Facades\Cart::instance('sale')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 1000,
            'weight' => 0,
            'options' => [
                'product_id' => $this->product->id,
                'product_code' => $this->product->product_code,
                'stock_managed' => true,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'sub_total' => 1000,
                'product_tax' => 0,
                'tax_id' => null,
                'unit_price' => 1000,
            ]
        ]);

        $this->post(route('sales.store'), [
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'customer_id' => $this->customer->id,
            'reference' => 'TEST-001',
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'status' => 'Pending',
            'payment_method' => 'Cash',
        ]);
        
        $sale = \Modules\Sale\Entities\Sale::first();
        
        // Update the sale
        \Gloudemans\Shoppingcart\Facades\Cart::instance('sale')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 3, // Change qty to 3
            'price' => 1000,
            'weight' => 0,
            'options' => [
                'product_id' => $this->product->id,
                'product_code' => $this->product->product_code,
                'stock_managed' => true,
                'product_discount' => 0,
                'product_discount_type' => 'fixed',
                'sub_total' => 3000,
                'product_tax' => 0,
                'tax_id' => null,
                'unit_price' => 1000,
            ]
        ]);

        $response = $this->patch(route('sales.update', $sale->id), [
            'date' => now()->format('Y-m-d'),
            'due_date' => now()->format('Y-m-d'),
            'customer_id' => $this->customer->id,
            'reference' => 'TEST-001',
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 3000,
            'paid_amount' => 0,
            'status' => 'Pending',
            'payment_method' => 'Cash',
        ]);
        
        $response->assertSessionHasNoErrors();
        
        // The old detail was deleted, new one created.
        $detail = SaleDetails::where('sale_id', $sale->id)->first();
        $this->assertEquals(500, $detail->cost_unit_snapshot);
        $this->assertEquals(1500, $detail->cost_total_snapshot); // 500 * 3
    }

    public function test_pos_checkout_populates_cost_snapshot()
    {
        $adapter = app(\Modules\Pos\Services\Adapters\InlinePosCheckoutPostingAdapter::class);
        $coa = \Modules\Setting\Entities\ChartOfAccount::create([
            'setting_id' => $this->settingA->id,
            'name' => 'Cash',
            'account_number' => '111',
            'category' => 'Kas & Bank',
            'is_default' => 1,
            'is_active' => 1
        ]);
        
        $paymentMethod = \Modules\Setting\Entities\PaymentMethod::create([
            'setting_id' => $this->settingA->id,
            'name' => 'CASH',
            'is_active' => true,
            'coa_id' => $coa->id,
        ]);
        
        $context = [
            'setting_id' => $this->settingA->id,
            'cashier_user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'checkout_id' => 1,
            'payment' => [
                'payment_method_id' => $paymentMethod->id,
            ],
            'cart_snapshot' => [
                'totals' => [
                    'grand_total' => 2000,
                    'discount_total' => 0,
                ],
                'lines' => [
                    [
                        'product_id' => $this->product->id,
                        'qty' => 2,
                        'unit_price' => 1000,
                        'line_subtotal' => 2000,
                        'stock_managed' => true,
                        'serial_number_required' => false,
                    ]
                ]
            ],
            'allocations' => [
                '0_P' => [
                    [
                        'allocated_qty' => 2,
                        'allocated_minor' => 200000,
                        'source_location_id' => $this->location->id,
                        'tax_policy_snapshot' => []
                    ]
                ]
            ]
        ];

        $adapter->post($context);

        $detail = SaleDetails::first();
        $this->assertNotNull($detail);
        $this->assertEquals(500, $detail->cost_unit_snapshot);
        $this->assertEquals(1000, $detail->cost_total_snapshot); // 500 * 2
        $this->assertEquals('CURRENT_AVERAGE_PRICE', $detail->cost_snapshot_source);
        $this->assertNotNull($detail->cost_snapshot_at);
    }

}
