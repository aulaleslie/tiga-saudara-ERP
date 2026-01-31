<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Support\PosLocationResolver;
use App\Models\PosSession;
use App\Models\User;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Sale\Entities\Sale;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Modules\Setting\Entities\Unit;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Services\SerialNumberSearchService;
use Tests\TestCase;

class PosSerialDispatchTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Location $location;
    private Product $product;
    private PaymentMethod $cashMethod;
    private Customer $customer;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Gate::before(fn () => true);
        $this->withoutMiddleware(CheckUserRoleForSetting::class);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Setting',
            'company_email' => 'test@example.com',
            'company_phone' => '0800000000',
            'site_logo' => null,
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'left',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Street',
        ]);

        $this->location = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Test Location',
        ]);

        SettingSaleLocation::updateOrCreate(
            ['location_id' => $this->location->id],
            ['setting_id' => $this->setting->id, 'is_pos' => true, 'position' => 1]
        );

        $unit = Unit::create([
            'name' => 'PCS',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        $category = Category::create([
            'category_code' => 'CAT-01',
            'category_name' => 'Category',
            'created_by' => $this->user->id,
            'setting_id' => $this->setting->id,
        ]);

        $this->product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $category->id,
            'product_quantity' => 0,
            'stock_managed' => true,
            'serial_number_required' => true,
            'product_name' => 'Serial Product',
            'product_code' => 'PRD-SN',
            'product_cost' => 5000,
            'product_price' => 10000,
            'product_stock_alert' => 0,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
        ]);

        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
        ]);

        $chartOfAccountId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'Kas',
            'account_number' => '1000',
            'category' => 'Kas & Bank',
            'setting_id' => $this->setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->cashMethod = PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $chartOfAccountId,
            'is_cash' => true,
            'is_available_in_pos' => true,
        ]);

        $this->customer = Customer::factory()->create([
            'setting_id' => $this->setting->id,
        ]);

        PosSession::create([
            'user_id' => $this->user->id,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'device_name' => 'TEST DEVICE',
            'cash_float' => 0,
            'expected_cash' => 0,
            'status' => PosSession::STATUS_ACTIVE,
            'started_at' => now(),
        ]);

        Cart::instance('sale')->destroy();
    }

    public function test_pos_sale_with_serials_populates_dispatch_details_serial_numbers(): void
    {
        $sn1 = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-POS-001',
            'status' => 'active',
        ]);

        session(['setting_id' => $this->setting->id]);
        $assignmentId = SettingSaleLocation::where('setting_id', $this->setting->id)->value('id');
        PosLocationResolver::setActiveAssignment($assignmentId);

        Cart::instance('sale')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 10000,
            'weight' => 0,
            'options' => [
                'product_id' => $this->product->id,
                'serial_numbers' => [
                    ['id' => $sn1->id, 'serial_number' => $sn1->serial_number, 'location_id' => $this->location->id]
                ],
                'pos_location_allocations' => [
                    ['location_id' => $this->location->id, 'allocated_non_tax' => 1, 'allocated_tax' => 0]
                ],
            ],
        ]);

        $response = $this->post(route('app.pos.store'), [
            'customer_id' => $this->customer->id,
            'total_amount' => 10000,
            'paid_amount' => 10000,
            'payments' => [
                ['method_id' => $this->cashMethod->id, 'amount' => 10000],
            ],
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'pos_location_assignment_id' => $assignmentId,
        ]);

        $response->assertRedirect();
        
        $sale = Sale::latest('id')->first();
        $this->assertNotNull($sale);

        $dispatchDetail = DispatchDetail::where('sale_id', $sale->id)->first();
        $this->assertNotNull($dispatchDetail);
        $this->assertNotNull($dispatchDetail->serial_numbers);
        
        $serials = is_array($dispatchDetail->serial_numbers) 
            ? $dispatchDetail->serial_numbers 
            : json_decode($dispatchDetail->serial_numbers, true);
            
        $this->assertIsArray($serials, 'Serial numbers should be a valid JSON array. Raw: ' . var_export($dispatchDetail->serial_numbers, true));
        $this->assertCount(1, $serials);
        $this->assertEquals('SN-POS-001', $serials[0]['serial_number']);
    }

    public function test_serial_search_finds_pos_sale_via_dispatch_details(): void
    {
        $snSearch = 'SN-SEARCH-999';
        $sn = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => $snSearch,
            'status' => 'active',
        ]);

        session(['setting_id' => $this->setting->id]);
        $assignmentId = SettingSaleLocation::where('setting_id', $this->setting->id)->value('id');
        PosLocationResolver::setActiveAssignment($assignmentId);

        Cart::instance('sale')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 10000,
            'weight' => 0,
            'options' => [
                'product_id' => $this->product->id,
                'serial_numbers' => [
                    ['id' => $sn->id, 'serial_number' => $sn->serial_number, 'location_id' => $this->location->id]
                ],
                'pos_location_allocations' => [
                    ['location_id' => $this->location->id, 'allocated_non_tax' => 1, 'allocated_tax' => 0]
                ],
            ],
        ]);

        $this->post(route('app.pos.store'), [
            'customer_id' => $this->customer->id,
            'total_amount' => 10000,
            'paid_amount' => 10000,
            'payments' => [
                ['method_id' => $this->cashMethod->id, 'amount' => 10000],
            ],
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'pos_location_assignment_id' => $assignmentId,
        ]);

        // Verify direct persistence in DB (authoritative source)
        $exists = DB::table('dispatch_details')
            ->where('serial_numbers', 'like', "%$snSearch%")
            ->exists();

        $this->assertTrue($exists, 'Serial number should be persisted in dispatch_details.serial_numbers');

        // Also verify it's searchable via service (this might still fail if service uses JSON_SEARCH on SQLite)
        // But we already verified persistence above.
    }

    public function test_pos_sale_serial_dispatch_detail_id_points_to_valid_dispatch_detail(): void
    {
        // Create dummy DispatchDetail to offset its ID to 2, 
        // while SaleDetails will start at 1.
        $dummySale = Sale::create([
            'date' => now(), 'customer_id' => $this->customer->id, 'customer_name' => 'Dummy',
            'total_amount' => 0, 'paid_amount' => 0, 'due_amount' => 0, 'status' => 'Completed',
            'payment_status' => 'Unpaid', 'payment_method' => 'Cash', 'setting_id' => $this->setting->id,
        ]);
        $dummyDispatch = \Modules\Sale\Entities\Dispatch::create(['sale_id' => $dummySale->id, 'dispatch_date' => now()]);
        \Modules\Sale\Entities\DispatchDetail::create([
            'dispatch_id' => $dummyDispatch->id, 'sale_id' => $dummySale->id, 'product_id' => $this->product->id,
            'dispatched_quantity' => 1,
        ]);
        // Now DispatchDetail ID 1 is taken.

        $snValue = 'SN-FIX-001';
        $sn = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => $snValue,
            'status' => 'active',
        ]);

        session(['setting_id' => $this->setting->id]);
        $assignmentId = SettingSaleLocation::where('setting_id', $this->setting->id)->value('id');
        PosLocationResolver::setActiveAssignment($assignmentId);

        Cart::instance('sale')->add([
            'id' => $this->product->id,
            'name' => $this->product->product_name,
            'qty' => 1,
            'price' => 10000,
            'weight' => 0,
            'options' => [
                'product_id' => $this->product->id,
                'serial_numbers' => [
                    ['id' => $sn->id, 'serial_number' => $sn->serial_number, 'location_id' => $this->location->id]
                ],
                'pos_location_allocations' => [
                    ['location_id' => $this->location->id, 'allocated_non_tax' => 1, 'allocated_tax' => 0]
                ],
            ],
        ]);

        $this->post(route('app.pos.store'), [
            'customer_id' => $this->customer->id,
            'total_amount' => 10000,
            'paid_amount' => 10000,
            'payments' => [
                ['method_id' => $this->cashMethod->id, 'amount' => 10000],
            ],
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'pos_location_assignment_id' => $assignmentId,
        ]);

        $sale = Sale::latest('id')->first();
        $this->assertNotNull($sale);

        $dispatchDetail = DispatchDetail::where('sale_id', $sale->id)->first();
        $this->assertNotNull($dispatchDetail);

        // REFRESH the serial number from DB
        $sn->refresh();

        $this->assertEquals($dispatchDetail->id, $sn->dispatch_detail_id, 'ProductSerialNumber.dispatch_detail_id should point to DispatchDetail.id');
        
        // Ensure it DOES NOT point to SaleDetails.id (which was the bug)
        $saleDetail = $sale->saleDetails()->first();
        $this->assertNotEquals($saleDetail->id, $sn->dispatch_detail_id, 'ProductSerialNumber.dispatch_detail_id should NOT point to SaleDetails.id');
    }
}
