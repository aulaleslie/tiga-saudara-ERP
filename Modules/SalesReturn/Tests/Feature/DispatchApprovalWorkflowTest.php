<?php

namespace Modules\SalesReturn\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement;
use Modules\Setting\Entities\Setting;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Location;
use Tests\TestCase;

class DispatchApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = OFF');

        Setting::create([
            'id' => 1,
            'company_name' => 'Test Company',
            'company_email' => 'test@test.com',
            'company_phone' => '123',
            'notification_email' => 'test@test.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        Product::create([
            'id' => 1,
            'product_name' => 'Test Product',
            'product_code' => 'P001',
            'product_quantity' => 10,
            'product_cost' => 500,
            'product_price' => 1000,
            'product_unit' => 'pc',
            'product_stock_alert' => 1,
            'setting_id' => 1,
        ]);

        Location::create([
            'id' => 1,
            'name' => 'Main Warehouse',
            'setting_id' => 1,
        ]);

        \Modules\Product\Entities\ProductStock::create([
            'product_id' => 1,
            'location_id' => 1,
            'quantity' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        session(['setting_id' => 1]);
        
        $user = User::factory()->create();
        $this->actingAs($user);
        
        Gate::define('saleReturnSettlements.dispatch', fn() => true);
        Gate::define('saleReturnSettlements.dispatchApproval', fn() => true);
    }

    protected function createApprovedSettlementItem(?int $serialNumberId = null): SaleReturnItemSettlement
    {
        $saleReturn = SaleReturn::create([
            'setting_id' => 1,
            'reference' => 'SR-' . uniqid(),
            'date' => now(),
            'customer_id' => 1,
            'customer_name' => 'Test Customer',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'approved',
            'status' => 'Awaiting Settlement',
        ]);

        $detail = SaleReturnDetail::create([
            'sale_return_id' => $saleReturn->id,
            'product_id' => 1,
            'product_name' => 'Test Product',
            'product_code' => 'P001',
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'location_id' => 1,
        ]);

        return SaleReturnItemSettlement::create([
            'sale_return_id' => $saleReturn->id,
            'sale_return_detail_id' => $detail->id,
            'product_serial_number_id' => $serialNumberId,
            'method' => 'REPAIR',
            'status' => SaleReturnItemSettlement::STATUS_APPROVED_AWAITING_DISPATCH,
            'nominal' => 1000,
        ]);
    }

    /** @test */
    public function it_transits_to_dispatch_requested_on_dispatch_submit()
    {
        $this->withoutExceptionHandling();
        $item = $this->createApprovedSettlementItem();

        $response = $this->post(route('sale-return-settlements.item.dispatch', $item->id), [
            'dispatched_serial_number' => 'NEW-SN-123',
            'dispatch_note' => 'Replacement unit',
        ]);

        $response->assertStatus(302);
        
        $item->refresh();
        $this->assertEquals(SaleReturnItemSettlement::STATUS_DISPATCH_REQUESTED, $item->status);
        $this->assertEquals('NEW-SN-123', $item->dispatched_serial_number);
        $this->assertEquals('REPLACEMENT UNIT', $item->dispatch_note);
        $this->assertNotNull($item->dispatch_requested_at);
        
        // Stock should NOT have changed yet
        $product = Product::find(1);
        $this->assertEquals(10, $product->product_quantity);
    }

    /** @test */
    public function it_can_approve_dispatch_request()
    {
        $this->withoutExceptionHandling();
        $item = $this->createApprovedSettlementItem();
        $item->update([
            'status' => SaleReturnItemSettlement::STATUS_DISPATCH_REQUESTED,
            'dispatched_serial_number' => null, // Non-serial test
            'location_id' => 1,
        ]);

        $response = $this->post(route('sale-return-settlements.item.dispatch.approve', $item->id));

        $response->assertStatus(302);
        
        $item->refresh();
        $this->assertEquals(SaleReturnItemSettlement::STATUS_DISPATCHED, $item->status);
        $this->assertNotNull($item->dispatch_approved_at);
        
        // Stock SHOULD have changed
        $product = Product::find(1);
        $this->assertEquals(9, $product->product_quantity);
    }

    /** @test */
    public function it_can_reject_dispatch_request()
    {
        $this->withoutExceptionHandling();
        $item = $this->createApprovedSettlementItem();
        $item->update(['status' => SaleReturnItemSettlement::STATUS_DISPATCH_REQUESTED]);

        $response = $this->post(route('sale-return-settlements.item.dispatch.reject', $item->id), [
            'rejection_reason' => 'Invalid unit selected',
        ]);

        $response->assertStatus(302);
        
        $item->refresh();
        $this->assertEquals(SaleReturnItemSettlement::STATUS_APPROVED_AWAITING_DISPATCH, $item->status);
        $this->assertEquals('INVALID UNIT SELECTED', $item->dispatch_rejection_reason);
        $this->assertNotNull($item->dispatch_rejected_at);
    }

    /** @test */
    public function it_fails_on_invalid_serial_number_dispatch()
    {
        $item = $this->createApprovedSettlementItem();
        // Product 1 requires serial
        $product = \Modules\Product\Entities\Product::find(1);
        $product->update(['serial_number_required' => true]);

        // 1. Serial not found
        $response = $this->post(route('sale-return-settlements.item.dispatch', $item->id), [
            'dispatched_serial_number' => 'NON-EXISTENT',
        ]);
        $response->assertSessionHas('error', "Serial Number NON-EXISTENT tidak ditemukan untuk produk ini.");
        
        // 2. Serial found but already sold (dispatch_detail_id is not null)
        $sn = \Modules\Product\Entities\ProductSerialNumber::create([
            'product_id' => 1,
            'serial_number' => 'SOLD-SN',
            'location_id' => 1,
            'dispatch_detail_id' => 99, // Assigned
            'status' => 'active',
        ]);

        $response = $this->post(route('sale-return-settlements.item.dispatch', $item->id), [
            'dispatched_serial_number' => 'SOLD-SN',
        ]);
        $response->assertSessionHas('error', "Serial Number SOLD-SN sudah digunakan atau tidak aktif.");

        // 3. Serial found but status not active
        \Modules\Product\Entities\ProductSerialNumber::create([
            'product_id' => 1,
            'serial_number' => 'INACTIVE-SN',
            'location_id' => 1,
            'dispatch_detail_id' => null,
            'status' => 'inactive',
        ]);

        $response = $this->post(route('sale-return-settlements.item.dispatch', $item->id), [
            'dispatched_serial_number' => 'INACTIVE-SN',
        ]);
        $response->assertSessionHas('error', "Serial Number INACTIVE-SN sudah digunakan atau tidak aktif.");
    }

    /** @test */
    public function it_allows_same_serial_for_repair_even_if_status_is_not_active()
    {
        $sn = \Modules\Product\Entities\ProductSerialNumber::create([
            'product_id' => 1,
            'location_id' => 1,
            'serial_number' => 'REPAIR-SN-' . uniqid(),
            'status' => 'active',
        ]);

        $item = $this->createApprovedSettlementItem($sn->id);
        $originalSN = $sn->serial_number;

        // Simulate original SN being "broken" or "in return process"
        $item->serialNumber->update(['status' => 'broken']);

        $response = $this->post(route('sale-return-settlements.item.dispatch', $item->id), [
            'dispatched_serial_number' => $originalSN,
        ]);

        $response->assertStatus(302);
        $response->assertSessionHas('success');
        
        $item->refresh();
        $this->assertEquals(SaleReturnItemSettlement::STATUS_DISPATCH_REQUESTED, $item->status);
    }
}
