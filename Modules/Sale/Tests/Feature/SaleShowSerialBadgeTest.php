<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\SerialNumberHistory;
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
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SaleShowSerialBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Setting $setting;

    protected PaymentTerm $paymentTerm;

    protected Customer $customer;

    protected Location $location;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('scout.driver', null);
        $this->withoutMiddleware(\App\Http\Middleware\CheckUserRoleForSetting::class);
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        Currency::create([
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
            'company_name' => 'Test Company',
            'company_email' => 'company@example.com',
            'company_phone' => '1234567890',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => '123 Testing Lane',
        ]);

        Permission::firstOrCreate(['name' => 'sales.show']);

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['sales.show']);
        $this->actingAs($this->user);

        session([
            'setting_id' => $this->setting->id,
            'user_settings' => collect([$this->setting]),
        ]);

        $this->paymentTerm = PaymentTerm::create(['name' => 'Net 30', 'longevity' => 30]);
        $this->customer = Customer::factory()->create([
            'setting_id' => $this->setting->id,
            'payment_term_id' => $this->paymentTerm->id,
        ]);

        $this->location = Location::create([
            'id' => 1,
            'name' => 'Main Warehouse',
            'setting_id' => $this->setting->id,
        ]);

        $category = Category::create([
            'category_name' => 'Category',
            'category_code' => 'CAT',
            'setting_id' => $this->setting->id,
            'created_by' => $this->user->id,
        ]);

        $unit = Unit::create([
            'name' => 'PCS',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
            'setting_id' => $this->setting->id,
        ]);

        $this->product = Product::create([
            'product_name' => 'Serial Badge Product',
            'product_code' => 'SBP-001',
            'setting_id' => $this->setting->id,
            'product_quantity' => 10,
            'product_cost' => 1000,
            'product_price' => 1500,
            'category_id' => $category->id,
            'product_unit' => $unit->id,
            'serial_number_required' => true,
            'stock_managed' => true,
        ]);
    }

    public function test_sale_show_uses_tracking_to_render_old_sale_red_and_new_sale_blue_for_same_serial(): void
    {
        [$saleA, $dispatchDetailA] = $this->createSaleWithApprovedDispatch('SO-OLD-001', 'SN-CTX-001');
        [$saleB, $dispatchDetailB] = $this->createSaleWithApprovedDispatch('SO-NEW-001', 'SN-CTX-001');

        $serial = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'dispatch_detail_id' => $dispatchDetailB->id,
            'serial_number' => 'SN-CTX-001',
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        SalesOrderSerialTracking::create([
            'sale_id' => $saleA->id,
            'product_serial_number_id' => $serial->id,
            'quantity_allocated' => 1,
            'dispatch_date' => now()->subDay(),
            'return_date' => now()->subHour(),
        ]);

        SalesOrderSerialTracking::create([
            'sale_id' => $saleB->id,
            'product_serial_number_id' => $serial->id,
            'quantity_allocated' => 1,
            'dispatch_date' => now(),
            'return_date' => null,
        ]);

        $responseOld = $this->get(route('sales.show', $saleA));
        $responseOld->assertStatus(200);
        $responseOld->assertSee('SN-CTX-001');
        $responseOld->assertSee('title="Sudah diretur dari penjualan ini', false);
        $responseOld->assertDontSee('title="Masih aktif pada penjualan ini"', false);

        $responseNew = $this->get(route('sales.show', $saleB));
        $responseNew->assertStatus(200);
        $responseNew->assertSee('SN-CTX-001');
        $responseNew->assertSee('title="Masih aktif pada penjualan ini"', false);
        $responseNew->assertDontSee('title="Sudah diretur dari penjualan ini"', false);
    }

    public function test_sale_show_legacy_fallback_renders_returned_serial_red_without_tracking_row(): void
    {
        [$sale, $dispatchDetail] = $this->createSaleWithApprovedDispatch('SO-LEGACY-001', 'SN-LEGACY-RED');

        $serial = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'dispatch_detail_id' => null,
            'serial_number' => 'SN-LEGACY-RED',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
        ]);

        $saleReturn = SaleReturn::create([
            'sale_id' => $sale->id,
            'reference' => 'SR-LEGACY-001',
            'setting_id' => $this->setting->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
            'location_id' => $this->location->id,
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
            'status' => 'Awaiting Settlement',
        ]);

        $saleReturnDetail = SaleReturnDetail::create([
            'sale_return_id' => $saleReturn->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 1500,
            'unit_price' => 1500,
            'sub_total' => 1500,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'dispatch_detail_id' => $dispatchDetail->id,
            'location_id' => $this->location->id,
            'tax_id' => null,
            'serial_number_ids' => [$serial->id],
        ]);

        SerialNumberHistory::create([
            'product_serial_number_id' => $serial->id,
            'event_type' => SerialNumberHistory::EVENT_SALE_RETURNED,
            'location_id' => $this->location->id,
            'reference_type' => SaleReturnDetail::class,
            'reference_id' => $saleReturnDetail->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->get(route('sales.show', $sale));

        $response->assertStatus(200);
        $response->assertSee('SN-LEGACY-RED');
        $response->assertSee('title="Sudah diretur dari penjualan ini', false);
    }

    public function test_sale_show_renders_returned_original_red_and_pos_replacement_serial_blue_when_quantity_differs_from_historical_badges(): void
    {
        [$sale, $originalDispatchDetail] = $this->createSaleWithApprovedDispatch('SO-POS-RET-001', 'SN-ORIGINAL-001');

        $returnedSerial = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'dispatch_detail_id' => null,
            'serial_number' => 'SN-ORIGINAL-001',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
        ]);

        SalesOrderSerialTracking::create([
            'sale_id' => $sale->id,
            'product_serial_number_id' => $returnedSerial->id,
            'quantity_allocated' => 1,
            'dispatch_date' => now()->subDays(2),
            'return_date' => now()->subDay()->startOfMinute(),
        ]);

        $replacementDispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now()->startOfMinute(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);

        $replacementDispatchDetail = DispatchDetail::create([
            'dispatch_id' => $replacementDispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
            'tax_id' => null,
            'serial_numbers' => json_encode(['SN-REPLACEMENT-001']),
            'replacement_of_dispatch_detail_id' => $originalDispatchDetail->id,
            'replacement_returned_serial_id' => $returnedSerial->id,
        ]);

        $replacementSerial = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'dispatch_detail_id' => $replacementDispatchDetail->id,
            'serial_number' => 'SN-REPLACEMENT-001',
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        SalesOrderSerialTracking::create([
            'sale_id' => $sale->id,
            'product_serial_number_id' => $replacementSerial->id,
            'quantity_allocated' => 1,
            'dispatch_date' => $replacementDispatch->dispatch_date,
            'return_date' => null,
        ]);

        $originalDispatchDetail->update(['dispatched_quantity' => 0]);

        $response = $this->get(route('sales.show', $sale));

        $response->assertStatus(200);
        $response->assertSee('SN-ORIGINAL-001');
        $response->assertSee('SN-REPLACEMENT-001');
        $response->assertSee('title="Sudah diretur dari penjualan ini pada '.now()->subDay()->startOfMinute()->format('Y-m-d H:i').'"', false);
        $response->assertSee('title="Serial pengganti POS retur', false);
        $response->assertSee('SN-ORIGINAL-001 dikirim pada '.now()->startOfMinute()->format('Y-m-d H:i'), false);
    }

    /**
     * @return array{0: Sale, 1: DispatchDetail}
     */
    protected function createSaleWithApprovedDispatch(string $reference, string $serialNumber): array
    {
        $sale = Sale::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->customer_name,
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
            'payment_term_id' => $this->paymentTerm->id,
            'setting_id' => $this->setting->id,
            'is_tax_included' => false,
            'reference' => $reference,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
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
            'product_id' => $this->product->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
            'tax_id' => null,
            'serial_numbers' => json_encode([$serialNumber]),
        ]);

        return [$sale, $dispatchDetail];
    }
}
