<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SaleReturnSerialHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Location $location;
    private Product $product;
    private Setting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::create([
            'id' => 1,
            'company_name' => 'Test Company',
            'company_email' => 'test@test.com',
            'company_phone' => '1234567890',
            'notification_email' => 'test@test.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => '123 Street',
        ]);

        Permission::firstOrCreate(['name' => 'saleReturns.receive']);
        
        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['saleReturns.receive']);
        $this->actingAs($this->user);

        $this->location = Location::create([
            'name' => 'Test Location',
            'setting_id' => 1
        ]);

        $paymentTerm = PaymentTerm::create(['name' => 'Net 30', 'longevity' => 30]);
        Customer::factory()->create(['id' => 1, 'setting_id' => 1, 'payment_term_id' => $paymentTerm->id]);

        $category = Category::create([
            'category_name' => 'Test Category',
            'category_code' => 'TEST',
            'setting_id' => 1,
            'created_by' => $this->user->id
        ]);

        $unit = Unit::create([
            'name' => 'Unit',
            'short_name' => 'U',
            'operator' => '*',
            'operation_value' => 1,
            'setting_id' => 1,
        ]);

        $this->product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TP01',
            'product_quantity' => 10,
            'product_cost' => 5000000,
            'product_price' => 7000000,
            'category_id' => $category->id,
            'product_unit' => $unit->id,
            'setting_id' => 1,
            'serial_number_required' => true
        ]);
    }

    /**
     * Test that receiving a sale return records SALE_RETURNED history for serial numbers.
     */
    public function test_receiving_sale_return_records_sale_returned_history()
    {
        // 1. Setup Sale and Dispatch
        $sale = Sale::create([
            'date' => now(),
            'due_date' => now()->addDays(7),
            'customer_id' => 1,
            'customer_name' => 'Test Customer',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 7000000,
            'paid_amount' => 0,
            'due_amount' => 7000000,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => 1,
            'reference' => 'SO-TEST-001'
        ]);

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_APPROVED,
        ]);

        $dispatchDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
        ]);

        // 2. Setup Serial Number (already dispatched)
        $sn1 = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-RETURN-001',
            'status' => 'active',
            'dispatch_detail_id' => $dispatchDetail->id
        ]);

        // 3. Setup Sale Return
        $saleReturn = SaleReturn::create([
            'date' => now(),
            'sale_id' => $sale->id,
            'customer_id' => 1,
            'customer_name' => 'Test Customer',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 7000000,
            'paid_amount' => 0,
            'due_amount' => 7000000,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'approved',
            'status' => 'Awaiting Receiving',
            'setting_id' => 1,
            'reference' => 'SR-TEST-001',
            'location_id' => $this->location->id
        ]);

        $saleReturnDetail = SaleReturnDetail::create([
            'sale_return_id' => $saleReturn->id,
            'dispatch_detail_id' => $dispatchDetail->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 7000000,
            'unit_price' => 7000000,
            'sub_total' => 7000000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'serial_number_ids' => [$sn1->id]
        ]);

        // 4. Receive Sale Return
        session(['setting_id' => 1]);
        $response = $this->post(route('sale-returns.receive', $saleReturn));
        $response->assertRedirect();

        // 5. Verify Serial Number updated
        $sn1->refresh();
        $this->assertNull($sn1->dispatch_detail_id);
        $this->assertEquals($this->location->id, $sn1->location_id);

        // 6. Verify History Recorded
        $this->assertDatabaseHas('serial_number_histories', [
            'product_serial_number_id' => $sn1->id,
            'event_type' => SerialNumberHistory::EVENT_SALE_RETURNED,
            'location_id' => $this->location->id,
            'reference_type' => SaleReturnDetail::class,
            'reference_id' => $saleReturnDetail->id,
            'user_id' => $this->user->id,
        ]);
    }
}
