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
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DispatchApprovalHistoryTest extends TestCase
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

        Permission::firstOrCreate(['name' => 'sales.dispatch']);
        Permission::firstOrCreate(['name' => 'sales.approval']);

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['sales.dispatch', 'sales.approval']);
        $this->actingAs($this->user);

        $this->location = Location::create([
            'name' => 'Test Location',
            'setting_id' => 1
        ]);

        $paymentTerm = PaymentTerm::create(['name' => 'Net 30', 'longevity' => 30]);
        Customer::factory()->create(['setting_id' => 1, 'payment_term_id' => $paymentTerm->id]);

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
     * Test that approving a dispatch creates SOLD history for serial numbers.
     */
    public function test_approving_dispatch_creates_sold_history_for_serial_numbers()
    {
        // 1. Setup Serial Numbers
        $sn1 = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-DISPATCH-001',
            'status' => 'active',
        ]);

        // 2. Setup Sale and Pending Dispatch
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
            'status' => Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => 1,
            'reference' => 'SO-DISPATCH-001'
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 7000000,
            'unit_price' => 7000000,
            'sub_total' => 7000000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now(),
            'status' => Dispatch::STATUS_PENDING,
        ]);

        $dispatchDetail = DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'dispatched_quantity' => 1,
            'location_id' => $this->location->id,
            'serial_numbers' => json_encode(['SN-DISPATCH-001']),
        ]);

        // 3. Mock stock for approval
        \Modules\Product\Entities\ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // 4. Approve Dispatch
        session(['setting_id' => 1]);
        $response = $this->post(route('dispatches.approve', $dispatch));
        $response->assertRedirect();

        // 5. Verify Serial Number updated
        $sn1->refresh();
        $this->assertEquals($dispatchDetail->id, $sn1->dispatch_detail_id);

        // 6. Verify History Recorded
        $this->assertDatabaseHas('serial_number_histories', [
            'product_serial_number_id' => $sn1->id,
            'event_type' => SerialNumberHistory::EVENT_SOLD,
            'location_id' => $this->location->id,
            'reference_type' => DispatchDetail::class,
            'reference_id' => $dispatchDetail->id,
            'user_id' => $this->user->id,
        ]);
    }
}
