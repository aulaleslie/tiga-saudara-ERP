<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DispatchApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function createSetup()
    {
        $setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'company@example.com',
            'company_phone' => '1234567890',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => '123 Testing Lane',
        ]);

        $paymentTerm = PaymentTerm::create(['name' => 'Net 30', 'longevity' => 30]);
        $customer = Customer::factory()->create(['setting_id' => $setting->id, 'payment_term_id' => $paymentTerm->id]);
        
        Permission::firstOrCreate(['name' => 'sales.dispatch']);
        Permission::firstOrCreate(['name' => 'sales.approval']);
        
        $user = User::factory()->create();
        $user->givePermissionTo(['sales.dispatch', 'sales.approval']);

        $category = \Modules\Product\Entities\Category::create([
            'category_name' => 'Category', 
            'category_code' => 'CAT', 
            'setting_id' => $setting->id,
            'created_by' => $user->id
        ]);
        $unit = \Modules\Setting\Entities\Unit::create([
            'name' => 'Unit', 
            'short_name' => 'U', 
            'operator' => '*', 
            'operation_value' => 1, 
            'setting_id' => $setting->id,
        ]);

        $product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TST-001',
            'setting_id' => $setting->id,
            'product_quantity' => 100,
            'product_cost' => 1000,
            'product_price' => 1500,
            'category_id' => $category->id,
            'product_unit' => $unit->id,
        ]);

        $location = Location::create([
            'name' => 'Warehouse 1',
            'setting_id' => $setting->id,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 100,
            'quantity_tax' => 0,
            'quantity_non_tax' => 100,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity' => 0,
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
            'status' => 'Approved',
            'payment_status' => 'Unpaid',
            'payment_method' => 'cash',
            'payment_term_id' => $paymentTerm->id,
            'setting_id' => $setting->id,
            'is_tax_included' => false,
            'reference' => 'SO-001'
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 10,
            'price' => 1500,
            'unit_price' => 1500,
            'sub_total' => 15000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        return [$setting, $user, $sale, $product, $location];
    }

    public function test_dispatch_creation_stays_pending_and_no_stock_deduction(): void
    {
        [$setting, $user, $sale, $product, $location] = $this->createSetup();

        $compositeKey = $product->id . '--0';
        $payload = [
            'dispatch_date' => now()->toDateString(),
            'dispatchedQuantities' => [$compositeKey => 5],
            'selectedLocations' => [$compositeKey => $location->id],
        ];

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('sales.storeDispatch', $sale), $payload);

        $response->assertRedirect();
        
        $dispatch = Dispatch::where('sale_id', $sale->id)->first();
        $this->assertNotNull($dispatch);
        $this->assertEquals(Dispatch::STATUS_PENDING, $dispatch->status);

        // Verify stock NOT deducted
        $product->refresh();
        $this->assertEquals(100, $product->product_quantity);
        
        $stock = ProductStock::where('product_id', $product->id)->where('location_id', $location->id)->first();
        $this->assertEquals(100, $stock->quantity);
    }

    public function test_dispatch_approval_deducts_stock_and_updates_status(): void
    {
        [$setting, $user, $sale, $product, $location] = $this->createSetup();

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now()->toDateString(),
            'status' => Dispatch::STATUS_PENDING,
        ]);

        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 5,
            'location_id' => $location->id,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('dispatches.approve', $dispatch));

        $response->assertRedirect();
        
        $dispatch->refresh();
        $this->assertEquals(Dispatch::STATUS_APPROVED, $dispatch->status);
        $this->assertEquals($user->id, $dispatch->approved_by);

        // Verify stock deducted
        $product->refresh();
        $this->assertEquals(95, $product->product_quantity);
        
        $stock = ProductStock::where('product_id', $product->id)->where('location_id', $location->id)->first();
        $this->assertEquals(95, $stock->quantity);

        // Verify sale status
        $sale->refresh();
        $this->assertEquals(Sale::STATUS_DISPATCHED_PARTIALLY, $sale->status);
    }

    public function test_dispatch_rejection_updates_status(): void
    {
        [$setting, $user, $sale, $product, $location] = $this->createSetup();

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now()->toDateString(),
            'status' => Dispatch::STATUS_PENDING,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('dispatches.reject', $dispatch), [
                'rejection_reason' => 'Damaged goods'
            ]);

        $response->assertRedirect();
        
        $dispatch->refresh();
        $this->assertEquals(Dispatch::STATUS_REJECTED, $dispatch->status);
        $this->assertEquals('DAMAGED GOODS', $dispatch->rejection_reason); // BaseModel uppercases this!

        // Verify stock NOT deducted
        $product->refresh();
        $this->assertEquals(100, $product->product_quantity);
    }

    public function test_approve_dispatch_fails_if_stock_insufficient(): void
    {
        [$setting, $user, $sale, $product, $location] = $this->createSetup();

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now()->toDateString(),
            'status' => Dispatch::STATUS_PENDING,
        ]);

        DispatchDetail::create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 10,
            'location_id' => $location->id,
        ]);

        // Manually reduce stock before approval
        $stock = ProductStock::where('product_id', $product->id)->where('location_id', $location->id)->first();
        $stock->update(['quantity' => 5]);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('dispatches.approve', $dispatch));

        $response->assertRedirect();
        // Check that session has error (toast error implementation usually uses flash session)
        // Since I'm using toast() helper, I'll check the session.
        
        $dispatch->refresh();
        $this->assertEquals(Dispatch::STATUS_PENDING, $dispatch->status, "Dispatch should stay pending if stock is insufficient");
    }
}
