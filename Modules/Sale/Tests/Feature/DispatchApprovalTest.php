<?php

namespace Modules\Sale\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Customer;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
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
        Permission::firstOrCreate(['name' => 'salesDispatches.approval']);
        
        $user = User::factory()->create();
        $user->givePermissionTo(['sales.dispatch', 'salesDispatches.approval']);

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
            'stock_managed' => true,
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

        // Force mismatched status so reject flow must recalculate to current dispatch reality.
        $sale->update(['status' => Sale::STATUS_DISPATCHED_PARTIALLY]);

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
        $this->assertEquals($user->id, $dispatch->approved_by);
        $this->assertNotNull($dispatch->approved_at);

        // Sale status must be recalculated after rejection.
        $sale->refresh();
        $this->assertEquals(Sale::STATUS_APPROVED, $sale->status);

        // Verify stock NOT deducted
        $product->refresh();
        $this->assertEquals(100, $product->product_quantity);

        // No inventory transaction should exist
        $transactionCount = Transaction::where('product_id', $product->id)->count();
        $this->assertEquals(0, $transactionCount);
    }

    public function test_dispatch_rejection_requires_reason(): void
    {
        [$setting, $user, $sale] = $this->createSetup();

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now()->toDateString(),
            'status' => Dispatch::STATUS_PENDING,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('dispatches.reject', $dispatch), [
                'rejection_reason' => '',
            ]);

        $response->assertSessionHasErrors(['rejection_reason']);
        $dispatch->refresh();
        $this->assertEquals(Dispatch::STATUS_PENDING, $dispatch->status);
    }

    public function test_rejected_dispatch_cannot_be_reprocessed_from_same_document(): void
    {
        [$setting, $user, $sale] = $this->createSetup();

        $dispatch = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now()->toDateString(),
            'status' => Dispatch::STATUS_REJECTED,
            'rejection_reason' => 'INITIAL REJECT',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('dispatches.reject', $dispatch), [
                'rejection_reason' => 'Retry reject',
            ]);

        $response->assertRedirect();
        $dispatch->refresh();
        $this->assertEquals(Dispatch::STATUS_REJECTED, $dispatch->status);
        $this->assertEquals('INITIAL REJECT', $dispatch->rejection_reason);
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

        $dispatch->refresh();
        $this->assertEquals(Dispatch::STATUS_PENDING, $dispatch->status, "Dispatch should stay pending if stock is insufficient");
    }

    public function test_submitting_dispatch_persists_correct_inventory_managed_snapshot(): void
    {
        [$setting, $user, $sale, $product, $location] = $this->createSetup();

        // Create a non-stock service product and add to sale
        $serviceCategory = \Modules\Product\Entities\Category::create([
            'category_name' => 'Services',
            'category_code' => 'SRV',
            'setting_id' => $setting->id,
            'created_by' => $user->id,
        ]);
        $unit = \Modules\Setting\Entities\Unit::where('setting_id', $setting->id)->first();
        $service = Product::create([
            'product_name' => 'Consulting Service',
            'product_code' => 'SRV-001',
            'setting_id' => $setting->id,
            'product_quantity' => 0,
            'product_cost' => 0,
            'stock_managed' => false,
            'product_price' => 500,
            'category_id' => $serviceCategory->id,
            'product_unit' => $unit->id,
        ]);

        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $service->id,
            'product_name' => $service->product_name,
            'product_code' => $service->product_code,
            'quantity' => 2,
            'price' => 500,
            'unit_price' => 500,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        $stockKey = $product->id . '--0';
        $serviceKey = $service->id . '--0';

        $payload = [
            'dispatch_date' => now()->toDateString(),
            'dispatchedQuantities' => [
                $stockKey => 3,
                $serviceKey => 2,
            ],
            'selectedLocations' => [
                $stockKey => $location->id,
            ],
        ];

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('sales.storeDispatch', $sale), $payload);

        $response->assertRedirect(route('sales.dispatches.index'));

        $dispatch = Dispatch::where('sale_id', $sale->id)->first();
        $this->assertNotNull($dispatch);

        $stockDetail = DispatchDetail::where('dispatch_id', $dispatch->id)
            ->where('product_id', $product->id)
            ->first();
        $this->assertNotNull($stockDetail);
        $this->assertTrue((bool) $stockDetail->is_inventory_managed);

        $serviceDetail = DispatchDetail::where('dispatch_id', $dispatch->id)
            ->where('product_id', $service->id)
            ->first();
        $this->assertNotNull($serviceDetail);
        $this->assertFalse((bool) $serviceDetail->is_inventory_managed);
    }

    public function test_concurrent_submissions_requesting_same_last_outstanding_quantity_only_one_succeeds(): void
    {
        [$setting, $user, $sale, $product, $location] = $this->createSetup();

        $compositeKey = $product->id . '--0';
        $payload = [
            'dispatch_date' => now()->toDateString(),
            'dispatchedQuantities' => [$compositeKey => 10], // Total order quantity is 10
            'selectedLocations' => [$compositeKey => $location->id],
        ];

        // First submission creates pending dispatch for 10
        $response1 = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('sales.storeDispatch', $sale), $payload);

        $response1->assertRedirect(route('sales.dispatches.index'));
        $this->assertEquals(1, Dispatch::where('sale_id', $sale->id)->count());

        // Second submission attempts to request the same 10 under the lock
        $response2 = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('sales.storeDispatch', $sale), $payload);

        $response2->assertSessionHasErrors(["dispatchedQuantities.$compositeKey"]);
        $this->assertEquals(1, Dispatch::where('sale_id', $sale->id)->count());
        $this->assertEquals(1, DispatchDetail::where('sale_id', $sale->id)->count());
    }

    public function test_concurrent_approval_requests_targeting_same_pending_dispatch_effects_applied_once(): void
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
            'dispatched_quantity' => 4,
            'is_inventory_managed' => true,
            'location_id' => $location->id,
        ]);

        // First approval succeeds
        $response1 = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('dispatches.approve', $dispatch));
        $response1->assertRedirect();

        $dispatch->refresh();
        $this->assertEquals(Dispatch::STATUS_APPROVED, $dispatch->status);

        $product->refresh();
        $this->assertEquals(96, $product->product_quantity);
        $stock = ProductStock::where('product_id', $product->id)->where('location_id', $location->id)->first();
        $this->assertEquals(96, $stock->quantity);
        $this->assertEquals(1, Transaction::where('product_id', $product->id)->count());

        // Second approval finds non-pending under lock and makes no further deductions
        $response2 = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('dispatches.approve', $dispatch));
        $response2->assertRedirect();

        $product->refresh();
        $this->assertEquals(96, $product->product_quantity);
        $stock->refresh();
        $this->assertEquals(96, $stock->quantity);
        $this->assertEquals(1, Transaction::where('product_id', $product->id)->count());
    }

    public function test_reclassification_between_submission_and_approval_fails_with_conflict_and_leaves_pending(): void
    {
        [$setting, $user, $sale, $product, $location] = $this->createSetup();

        // 1. Stock-managed submitted, but reclassified to non-stock before approval
        $dispatch1 = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now()->toDateString(),
            'status' => Dispatch::STATUS_PENDING,
        ]);

        DispatchDetail::create([
            'dispatch_id' => $dispatch1->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 2,
            'is_inventory_managed' => true,
            'location_id' => $location->id,
        ]);

        // Reclassify product to non-stock
        $product->update(['stock_managed' => false]);

        $response1 = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('dispatches.approve', $dispatch1));

        $response1->assertRedirect();
        $dispatch1->refresh();
        $this->assertEquals(Dispatch::STATUS_PENDING, $dispatch1->status);
        $this->assertEquals(100, $product->fresh()->product_quantity);

        // 2. Non-stock submitted, but reclassified to stock-managed before approval
        $unit = \Modules\Setting\Entities\Unit::where('setting_id', $setting->id)->first();
        $service = Product::create([
            'product_name' => 'Cleaning Service',
            'product_code' => 'CLN-001',
            'setting_id' => $setting->id,
            'product_quantity' => 0,
            'product_cost' => 0,
            'stock_managed' => false,
            'product_price' => 200,
            'category_id' => $product->category_id,
            'product_unit' => $unit->id,
        ]);

        $dispatch2 = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now()->toDateString(),
            'status' => Dispatch::STATUS_PENDING,
        ]);

        DispatchDetail::create([
            'dispatch_id' => $dispatch2->id,
            'sale_id' => $sale->id,
            'product_id' => $service->id,
            'dispatched_quantity' => 1,
            'is_inventory_managed' => false,
            'location_id' => null,
        ]);

        // Reclassify service to stock-managed
        $service->update(['stock_managed' => true]);

        $response2 = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('dispatches.approve', $dispatch2));

        $response2->assertRedirect();
        $dispatch2->refresh();
        $this->assertEquals(Dispatch::STATUS_PENDING, $dispatch2->status);
    }

    public function test_legacy_null_snapshot_inference_and_ambiguity_handling(): void
    {
        [$setting, $user, $sale, $product, $location] = $this->createSetup();

        // 1. Unambiguous inventory evidence (has location_id)
        $dispatch1 = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now()->toDateString(),
            'status' => Dispatch::STATUS_PENDING,
        ]);

        DispatchDetail::create([
            'dispatch_id' => $dispatch1->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 3,
            'is_inventory_managed' => null, // Legacy null snapshot
            'location_id' => $location->id,
        ]);

        $response1 = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('dispatches.approve', $dispatch1));

        $response1->assertRedirect();
        $dispatch1->refresh();
        $this->assertEquals(Dispatch::STATUS_APPROVED, $dispatch1->status);
        $this->assertEquals(97, $product->fresh()->product_quantity);

        // 2. Unambiguous non-stock evidence (no location, no serials, product stock_managed=false)
        $unit = \Modules\Setting\Entities\Unit::where('setting_id', $setting->id)->first();
        $service = Product::create([
            'product_name' => 'Legacy Non Stock',
            'product_code' => 'LEG-001',
            'setting_id' => $setting->id,
            'product_quantity' => 0,
            'product_cost' => 0,
            'stock_managed' => false,
            'serial_number_required' => false,
            'product_price' => 100,
            'category_id' => $product->category_id,
            'product_unit' => $unit->id,
        ]);

        $dispatch2 = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now()->toDateString(),
            'status' => Dispatch::STATUS_PENDING,
        ]);

        DispatchDetail::create([
            'dispatch_id' => $dispatch2->id,
            'sale_id' => $sale->id,
            'product_id' => $service->id,
            'dispatched_quantity' => 1,
            'is_inventory_managed' => null, // Legacy null snapshot
            'location_id' => null,
            'serial_numbers' => null,
        ]);

        $response2 = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('dispatches.approve', $dispatch2));

        $response2->assertRedirect();
        $dispatch2->refresh();
        $this->assertEquals(Dispatch::STATUS_APPROVED, $dispatch2->status);

        // 3. Ambiguous legacy detail: product is serial-required stock-managed, but detail has no location and no serials
        $serialProduct = Product::create([
            'product_name' => 'Serial Product',
            'product_code' => 'SER-001',
            'setting_id' => $setting->id,
            'product_quantity' => 10,
            'product_cost' => 500,
            'stock_managed' => true,
            'serial_number_required' => true,
            'product_price' => 1000,
            'category_id' => $product->category_id,
            'product_unit' => $unit->id,
        ]);

        $dispatch3 = Dispatch::create([
            'sale_id' => $sale->id,
            'dispatch_date' => now()->toDateString(),
            'status' => Dispatch::STATUS_PENDING,
        ]);

        DispatchDetail::create([
            'dispatch_id' => $dispatch3->id,
            'sale_id' => $sale->id,
            'product_id' => $serialProduct->id,
            'dispatched_quantity' => 1,
            'is_inventory_managed' => null, // Legacy null snapshot
            'location_id' => null,
            'serial_numbers' => null,
        ]);

        $response3 = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('dispatches.approve', $dispatch3));

        $response3->assertRedirect();
        $dispatch3->refresh();
        $this->assertEquals(Dispatch::STATUS_PENDING, $dispatch3->status, "Ambiguous legacy detail must remain pending and reject approval");
    }
}

