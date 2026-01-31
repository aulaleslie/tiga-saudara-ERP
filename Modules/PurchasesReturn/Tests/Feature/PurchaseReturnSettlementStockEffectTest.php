<?php

namespace Modules\PurchasesReturn\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchaseReturnSettlementStockEffectTest extends TestCase
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

    /** @test */
    public function it_deducts_stock_and_creates_transaction_on_dispatch_for_modify_purchase()
    {
        // 1. Setup product with stock
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Non-Serial Product',
            'product_code' => 'P001',
            'product_cost' => 10,
            'product_price' => 20,
            'product_quantity' => 10,
            'serial_number_required' => false
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
        ]);

        // 2. Setup Purchase to be modified
        $purchase = Purchase::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'reference' => 'PUR-001',
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

        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 10,
            'unit_price' => 500,
            'price' => 500,
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

        $receivedDetail = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 2,
        ]);

        // 3. Setup Purchase Return
        $pr = PurchaseReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'PRRN-001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'approved',
            'location_id' => $this->location->id,
            'return_dispatch_status' => 'pending_approval',
        ]);

        $detail = PurchaseReturnDetail::create([
            'purchase_return_id' => $pr->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'price' => 500,
            'unit_price' => 500,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
        ]);

        $item = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'MODIFY_PURCHASE',
            'nominal' => 1000,
            'status' => 'SUBMITTED',
            'target_purchase_id' => $purchase->id,
        ]);

        // 4. Approve dispatch (stock deduction happens here)
        $this->post(route('purchase-returns.dispatch-approve', $pr->id));

        // 5. Approve settlement (no stock deduction)
        $response = $this->post(route('purchase-return-settlements.item.approve', $item->id));
        $response->assertStatus(302);

        // 6. Verify Effects
        $product->refresh();
        $this->assertEquals(8, $product->product_quantity);

        $stock = ProductStock::where('product_id', $product->id)
            ->where('location_id', $this->location->id)
            ->first();
        $this->assertEquals(8, $stock->quantity);

        $purchaseDetail->refresh();
        $this->assertEquals(8, (int) $purchaseDetail->quantity);

        $receivedDetail->refresh();
        $this->assertEquals(0, (int) $receivedDetail->quantity_received);

        $this->assertDatabaseHas('transactions', [
            'product_id' => $product->id,
            'type' => 'PURCHASE_RETURN_GOOD_NON_TAX',
            'quantity' => -2,
            'current_quantity' => 8,
            'location_id' => $this->location->id,
            'reason' => strtoupper("Dispatch retur: {$pr->reference}"),
            'quantity_non_tax' => -2,
        ]);

        $purchase->refresh();
        $this->assertEquals(4000, (float) $purchase->total_amount);
        $this->assertEquals(3000, (float) $purchase->due_amount);
        $this->assertEquals(1000, (float) $purchase->paid_amount);
    }

    /** @test */
    public function it_marks_serial_number_as_returned_on_dispatch_for_modify_purchase()
    {
        // 1. Setup serial product
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Serial Product',
            'product_code' => 'P002',
            'product_cost' => 100,
            'product_price' => 200,
            'product_quantity' => 5,
            'serial_number_required' => true
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 5,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
        ]);

        $sn = ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-001',
            'status' => 'active',
            'location_id' => $this->location->id,
        ]);

        // 2. Setup Purchase Return
        $pr = PurchaseReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'PRRN-002',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'total_amount' => 500,
            'paid_amount' => 0,
            'due_amount' => 500,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'approved',
            'location_id' => $this->location->id,
            'return_dispatch_status' => 'pending_approval',
        ]);

        $detail = PurchaseReturnDetail::create([
            'purchase_return_id' => $pr->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 500,
            'unit_price' => 500,
            'sub_total' => 500,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
            'serial_number_ids' => [$sn->id],
        ]);

        $purchase = Purchase::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'reference' => 'PUR-002',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'total_amount' => 5000,
            'paid_amount' => 0,
            'due_amount' => 5000,
            'status' => 'Received',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);

        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 10,
            'unit_price' => 500,
            'price' => 500,
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

        $receivedDetail = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 1,
        ]);

        $sn->update(['received_note_detail_id' => $receivedDetail->id]);

        $item = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'product_serial_number_id' => $sn->id,
            'method' => 'MODIFY_PURCHASE',
            'nominal' => 500,
            'status' => 'SUBMITTED',
            'target_purchase_id' => $purchase->id,
        ]);

        // 3. Approve dispatch (serials returned here)
        $this->post(route('purchase-returns.dispatch-approve', $pr->id));

        // 4. Approve settlement (purchase modification only)
        $response = $this->post(route('purchase-return-settlements.item.approve', $item->id));
        $response->assertStatus(302);

        // 5. Verify Effects
        $sn->refresh();
        $this->assertEquals('RETURNED', $sn->status);
        $this->assertNull($sn->received_note_detail_id);

        $product->refresh();
        $this->assertEquals(4, $product->product_quantity);

        $stock = ProductStock::where('product_id', $product->id)
            ->where('location_id', $this->location->id)
            ->first();
        $this->assertEquals(4, $stock->quantity);

        $purchaseDetail->refresh();
        $this->assertEquals(9, (int) $purchaseDetail->quantity);

        $receivedDetail->refresh();
        $this->assertEquals(0, (int) $receivedDetail->quantity_received);

        $purchase->refresh();
        $this->assertEquals(4500, (float) $purchase->total_amount);
    }
}
