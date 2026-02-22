<?php

namespace Modules\SalesReturn\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Sale\Entities\SalesOrderSerialTracking;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SaleReturnReceiveSerialStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('scout.driver', null);

        $this->withoutMiddleware(\App\Http\Middleware\CheckUserRoleForSetting::class);

        Currency::create([
            'id' => 1,
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        Setting::create([
            'id' => 1,
            'company_name' => 'Test Company',
            'company_email' => 'company@example.com',
            'company_phone' => '1234567890',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => '123 Testing Lane',
        ]);

        session(['setting_id' => 1]);

        $user = User::factory()->create();
        $this->actingAs($user);

        Gate::define('saleReturns.receive', fn () => true);
    }

    /** @test */
    public function receiving_sales_return_restores_sold_serial_to_active_and_clears_dispatch_link(): void
    {
        $paymentTerm = PaymentTerm::create(['name' => 'Net 30', 'longevity' => 30]);
        $customer = Customer::factory()->create([
            'setting_id' => 1,
            'payment_term_id' => $paymentTerm->id,
        ]);

        $location = Location::create([
            'id' => 1,
            'name' => 'Main Warehouse',
            'setting_id' => 1,
        ]);

        $product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TP-001',
            'product_quantity' => 0,
            'product_cost' => 1000,
            'product_price' => 1500,
            'setting_id' => 1,
            'serial_number_required' => true,
        ]);

        $stock = ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 0,
            'quantity_tax' => 0,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

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
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'cash',
            'payment_term_id' => $paymentTerm->id,
            'setting_id' => 1,
            'is_tax_included' => false,
            'reference' => 'SO-RET-001',
        ]);

        SaleDetails::create([
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

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now()->toDateString(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);

        $dispatchDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
            'location_id' => $location->id,
            'tax_id' => null,
            'serial_numbers' => json_encode(['SN-RET-001']),
        ]);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'serial_number' => 'SN-RET-001',
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        SalesOrderSerialTracking::create([
            'sale_id' => $sale->id,
            'product_serial_number_id' => $serial->id,
            'quantity_allocated' => 1,
            'dispatch_date' => now(),
            'return_date' => null,
        ]);

        $saleReturn = SaleReturn::create([
            'sale_id' => $sale->id,
            'setting_id' => 1,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'location_id' => $location->id,
            'date' => now()->toDateString(),
            'total_amount' => 1500,
            'paid_amount' => 0,
            'due_amount' => 1500,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'approved',
            'status' => 'Awaiting Receiving',
        ]);

        $saleReturnDetail = SaleReturnDetail::create([
            'sale_return_id' => $saleReturn->id,
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
            'dispatch_detail_id' => $dispatchDetail->id,
            'location_id' => $location->id,
            'tax_id' => null,
            'serial_number_ids' => [$serial->id],
        ]);

        $this->assertSame([(int) $serial->id], array_map('intval', $saleReturnDetail->fresh()->serial_number_ids ?? []));
        $this->assertEquals($dispatchDetail->id, (int) $saleReturnDetail->fresh()->dispatch_detail_id);
        $this->assertEquals('approved', strtolower((string) $saleReturn->fresh()->approval_status));
        $this->assertEquals('awaiting receiving', strtolower((string) $saleReturn->fresh()->status));

        $response = $this->post(route('sale-returns.receive', $saleReturn));

        $response->assertRedirect();
        $this->assertFalse(session()->has('error'), (string) session('error'));

        $serial->refresh();
        $stock->refresh();
        $product->refresh();
        $saleReturn->refresh();

        $this->assertEquals(1, (int) $stock->quantity);
        $this->assertEquals(1, (int) $stock->quantity_non_tax);
        $this->assertEquals(1, (int) $product->product_quantity);
        $this->assertEquals('AWAITING SETTLEMENT', strtoupper((string) $saleReturn->status));

        $this->assertNull($serial->dispatch_detail_id);
        $this->assertEquals(ProductSerialNumber::STATUS_ACTIVE, $serial->status);
        $this->assertEquals($location->id, $serial->location_id);

        $tracking = SalesOrderSerialTracking::query()
            ->where('sale_id', $sale->id)
            ->where('product_serial_number_id', $serial->id)
            ->first();

        $this->assertNotNull($tracking);
        $this->assertNotNull($tracking->return_date);
    }
}
