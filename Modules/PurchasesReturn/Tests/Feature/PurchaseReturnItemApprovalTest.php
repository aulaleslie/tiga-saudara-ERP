<?php

namespace Modules\PurchasesReturn\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
use Modules\PurchasesReturn\Entities\PurchaseReturnPayment;
use Modules\PurchasesReturn\Entities\SupplierCredit;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchaseReturnItemApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $location;
    protected $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        \Illuminate\Support\Facades\Gate::before(fn () => true);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456',
            'company_address' => 'Test Address',
            'notification_email' => 'test@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'footer',
        ]);

        $this->location = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Main Warehouse',
        ]);

        $this->supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'supplier@example.com',
            'supplier_phone' => '123456',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);
    }

    private function createPurchaseReturn($total = 1000)
    {
        \Illuminate\Support\Facades\DB::table('purchase_returns')->insert([
            'date' => now()->toDateString(),
            'reference' => 'PRRN-' . uniqid(),
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'total_amount' => $total,
            'paid_amount' => 0,
            'due_amount' => $total,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return PurchaseReturn::latest('id')->first();
    }

    private function createDetail($purchaseReturn, $subTotal = 1000)
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Product',
            'product_code' => 'P' . uniqid(),
            'product_cost' => 10,
            'product_price' => 20,
            'serial_number_required' => false
        ]);

        return PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => $subTotal,
            'unit_price' => $subTotal,
            'sub_total' => $subTotal,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
        ]);
    }

    /** @test */
    public function it_can_approve_submitted_cash_line_and_create_payment()
    {
        $pr = $this->createPurchaseReturn(1000);
        $detail = $this->createDetail($pr, 1000);
        $item = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'CASH',
            'nominal' => 1000,
            'status' => 'SUBMITTED',
        ]);

        $response = $this->post(route('purchase-return-settlements.item.approve', $item->id));

        $response->assertStatus(302);
        $this->assertEquals('APPROVED', $item->fresh()->status);
        $this->assertDatabaseHas('purchase_return_payments', [
            'purchase_return_id' => $pr->id,
            'amount' => 1000,
        ]);
        $this->assertEquals('SETTLED', $pr->fresh()->status);
    }

    /** @test */
    public function it_can_approve_submitted_credit_line_and_create_supplier_credit()
    {
        $pr = $this->createPurchaseReturn(1000);
        $detail = $this->createDetail($pr, 1000);
        $item = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'CREDIT',
            'nominal' => 1000,
            'status' => 'SUBMITTED',
        ]);

        $response = $this->post(route('purchase-return-settlements.item.approve', $item->id));

        $response->assertStatus(302);
        $this->assertEquals('APPROVED', $item->fresh()->status);
        $this->assertDatabaseHas('supplier_credits', [
            'purchase_return_id' => $pr->id,
            'amount' => 1000,
            'remaining_amount' => 1000,
        ]);
    }

    /** @test */
    public function it_can_approve_submitted_modify_purchase_line_and_update_purchase()
    {
        $purchase = Purchase::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'reference' => 'PS-' . uniqid(),
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'total_amount' => 5000,
            'paid_amount' => 1000,
            'due_amount' => 4000,
            'status' => 'Received',
            'payment_status' => 'Partial',
            'payment_method' => 'Cash',
        ]);

        $pr = $this->createPurchaseReturn(1000);
        $detail = $this->createDetail($pr, 1000);

        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $detail->product_id,
            'product_name' => $detail->product_name,
            'product_code' => $detail->product_code,
            'quantity' => 5,
            'unit_price' => 1000,
            'price' => 1000,
            'product_discount_amount' => 0,
            'sub_total' => 5000,
            'product_tax_amount' => 0,
        ]);

        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'date' => now()->toDateString(),
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_APPROVED,
        ]);

        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 1,
        ]);
        $item = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'MODIFY_PURCHASE',
            'nominal' => 1000,
            'status' => 'SUBMITTED',
            'target_purchase_id' => $purchase->id,
        ]);

        $response = $this->post(route('purchase-return-settlements.item.approve', $item->id));

        $response->assertStatus(302);
        $this->assertEquals('APPROVED', $item->fresh()->status);
        
        $purchase = $purchase->fresh();
        $this->assertEquals(4000, (float) $purchase->total_amount);
        $this->assertEquals(3000, (float) $purchase->due_amount);
        $this->assertEquals(1000, (float) $purchase->paid_amount);
    }

    /** @test */
    public function it_blocks_approval_if_nominal_exceeds_subtotal()
    {
        $pr = $this->createPurchaseReturn(1000);
        $detail = $this->createDetail($pr, 1000);
        $item = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'CASH',
            'nominal' => 1500, // exceeds 1000
            'status' => 'SUBMITTED',
        ]);

        $response = $this->post(route('purchase-return-settlements.item.approve', $item->id));

        $response->assertStatus(302);
        $response->assertSessionHas('error', 'Gagal menyetujui item: Nominal penyelesaian melebihi subtotal item.');
        $this->assertEquals('SUBMITTED', $item->fresh()->status);
    }

    /** @test */
    public function it_rejects_line_and_clears_method()
    {
        $pr = $this->createPurchaseReturn(1000);
        $detail = $this->createDetail($pr, 1000);
        $item = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'CASH',
            'nominal' => 1000,
            'status' => 'SUBMITTED',
        ]);

        $response = $this->post(route('purchase-return-settlements.item.reject', $item->id), [
            'rejection_reason' => 'Invalid nominal',
        ]);

        $response->assertStatus(302);
        $item = $item->fresh();
        $this->assertEquals('REJECTED', $item->status);
        $this->assertNull($item->method);
        $this->assertEquals(0, (float) $item->nominal);
        $this->assertEquals('INVALID NOMINAL', $item->rejection_reason);
    }

    /** @test */
    public function it_blocks_double_approval()
    {
        $pr = $this->createPurchaseReturn(1000);
        $detail = $this->createDetail($pr, 1000);
        $item = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'CASH',
            'nominal' => 1000,
            'status' => 'SUBMITTED',
        ]);

        $this->post(route('purchase-return-settlements.item.approve', $item->id));
        
        $response = $this->post(route('purchase-return-settlements.item.approve', $item->id));
        $response->assertStatus(302);
        $this->assertEquals('Item ini tidak dapat disetujui.', session('error'));
    }

    /** @test */
    public function it_increments_existing_payment_instead_of_creating_new()
    {
        $pr = $this->createPurchaseReturn(2000);
        $detail1 = $this->createDetail($pr, 1000);
        $detail2 = $this->createDetail($pr, 1000);
        
        $item1 = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail1->id,
            'method' => 'CASH',
            'nominal' => 1000,
            'status' => 'SUBMITTED',
        ]);
        
        $item2 = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail2->id,
            'method' => 'CASH',
            'nominal' => 1000,
            'status' => 'SUBMITTED',
        ]);

        $this->post(route('purchase-return-settlements.item.approve', $item1->id));
        $this->post(route('purchase-return-settlements.item.approve', $item2->id));

        $this->assertEquals(1, PurchaseReturnPayment::where('purchase_return_id', $pr->id)->count());
        $this->assertEquals(2000, (float) PurchaseReturnPayment::where('purchase_return_id', $pr->id)->first()->amount);
    }

    /** @test */
    public function it_approves_product_repair_and_broken_stock_with_awaiting_receive_status()
    {
        $pr = $this->createPurchaseReturn(1000);
        $detail = $this->createDetail($pr, 1000);
        
        // Test PRODUCT_REPAIR
        $repairItem = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'PRODUCT_REPAIR',
            'nominal' => 500,
            'status' => 'SUBMITTED',
        ]);

        $response = $this->post(route('purchase-return-settlements.item.approve', $repairItem->id));
        $response->assertStatus(302);
        $this->assertEquals('APPROVED_AWAITING_RECEIVE', $repairItem->fresh()->status);

        // Test BROKEN_STOCK
        $brokenItem = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'BROKEN_STOCK',
            'nominal' => 500,
            'status' => 'SUBMITTED',
        ]);

        $response = $this->post(route('purchase-return-settlements.item.approve', $brokenItem->id));
        $response->assertStatus(302);
        $this->assertEquals('APPROVED_AWAITING_RECEIVE', $brokenItem->fresh()->status);
    }

    /** @test */
    public function it_can_receive_product_repair_and_broken_stock_items()
    {
        $pr = $this->createPurchaseReturn(1000);
        $detail = $this->createDetail($pr, 1000);
        
        // Create serial numbers for testing
        $repairSerial = \Modules\Product\Entities\ProductSerialNumber::create([
            'product_id' => $detail->product_id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-REPAIR-123',
            'status' => 'AVAILABLE',
        ]);
        
        $brokenSerial = \Modules\Product\Entities\ProductSerialNumber::create([
            'product_id' => $detail->product_id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-BROKEN-456',
            'status' => 'AVAILABLE',
        ]);
        
        // Create repair item
        $repairItem = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'product_serial_number_id' => $repairSerial->id,
            'method' => 'PRODUCT_REPAIR',
            'nominal' => 500,
            'status' => 'APPROVED_AWAITING_RECEIVE',
        ]);

        // Create another location for receiving
        $receiveLocation = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Repair Warehouse',
        ]);

        $response = $this->post(route('purchase-return-settlements.item.receive', $repairItem->id), [
            'location_id' => $receiveLocation->id,
            'received_quantity' => 1,
            'note' => 'Repaired successfully',
        ]);

        $response->assertStatus(302);
        $repairItem->refresh();
        $this->assertEquals('RECEIVED', $repairItem->status);
        $this->assertEquals($receiveLocation->id, $repairItem->received_location_id);
        $this->assertEquals(1, $repairItem->received_quantity);
        $this->assertEquals('Repaired successfully', $repairItem->received_note);
        
        // Check serial number was moved
        $repairSerial->refresh();
        $this->assertEquals($receiveLocation->id, $repairSerial->location_id);
        $this->assertEquals('AVAILABLE', $repairSerial->status);

        // Test BROKEN_STOCK
        $brokenItem = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'product_serial_number_id' => $brokenSerial->id,
            'method' => 'BROKEN_STOCK',
            'nominal' => 500,
            'status' => 'APPROVED_AWAITING_RECEIVE',
        ]);

        $response = $this->post(route('purchase-return-settlements.item.receive', $brokenItem->id), [
            'location_id' => $receiveLocation->id,
            'received_quantity' => 1,
            'note' => 'Marked as broken',
        ]);

        $response->assertStatus(302);
        $brokenItem->refresh();
        $this->assertEquals('RECEIVED', $brokenItem->status);
        
        // Check serial number was marked as broken
        $brokenSerial->refresh();
        $this->assertEquals($receiveLocation->id, $brokenSerial->location_id);
        $this->assertTrue($brokenSerial->is_broken);
        $this->assertEquals('BROKEN', $brokenSerial->status);
    }
}
