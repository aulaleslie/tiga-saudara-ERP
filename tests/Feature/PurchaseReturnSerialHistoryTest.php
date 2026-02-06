<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Tests\TestCase;

class PurchaseReturnSerialHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Location $location;
    private Product $product;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

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
            'company_email' => 'test@test.com',
            'company_phone' => '1234567890',
            'notification_email' => 'test@test.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => '123 Street',
        ]);

        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'purchaseReturnSettlements.approve']);
        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['purchaseReturnSettlements.approve']);
        $this->actingAs($this->user);

        $this->location = Location::create([
            'name' => 'Test Location',
            'setting_id' => 1
        ]);

        $this->supplier = Supplier::factory()->create([
            'setting_id' => 1,
        ]);

        $this->product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TP01',
            'product_quantity' => 10,
            'product_cost' => 5000000,
            'product_price' => 7000000,
            'product_unit' => 'pcs',
            'setting_id' => 1,
            'serial_number_required' => true
        ]);

        session(['setting_id' => 1]);
    }

    /**
     * Test that approving a MODIFY_PURCHASE settlement records PURCHASE_RETURNED history.
     */
    public function test_approving_modify_purchase_settlement_records_purchase_returned_history()
    {
        // 1. Setup Purchase
        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(7),
            'supplier_id' => $this->supplier->id,
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'total_amount' => 5000000,
            'paid_amount' => 5000000,
            'due_amount' => 0,
            'status' => 'Received',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'setting_id' => 1,
            'reference' => 'PO-TEST-001'
        ]);

        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 5000000,
            'unit_price' => 5000000,
            'sub_total' => 5000000,
            'product_tax_amount' => 0,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
        ]);

        $receivedNote = ReceivedNote::create([
            'date' => now(),
            'reference' => 'GRN-TEST-001',
            'supplier_id' => $this->supplier->id,
            'setting_id' => 1,
            'status' => ReceivedNote::STATUS_APPROVED,
            'po_id' => $purchase->id,
        ]);

        $receivedNoteDetail = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'product_id' => $this->product->id,
            'quantity_received' => 1,
            'product_code' => $this->product->product_code,
            'product_name' => $this->product->product_name,
            'unit_price' => 5000000,
            'sub_total' => 5000000,
            'product_tax_amount' => 0,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
        ]);

        // 2. Setup Serial Number
        $sn = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-PR-001',
            'status' => 'active',
            'received_note_detail_id' => $receivedNoteDetail->id
        ]);

        // 3. Setup Purchase Return
        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PR-TEST-001',
            'setting_id' => 1,
            'location_id' => $this->location->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'payment_method' => 'Cash',
            'total_amount' => 5000000,
            'paid_amount' => 0,
            'due_amount' => 5000000,
            'status' => 'Pending',
            'approval_status' => 'Approved',
            'payment_status' => 'Unpaid',
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
        ]);

        $returnDetail = PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 5000000,
            'unit_price' => 5000000,
            'sub_total' => 5000000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        // 4. Create Settlement Item
        $settlementItem = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $purchaseReturn->id,
            'purchase_return_detail_id' => $returnDetail->id,
            'product_serial_number_id' => $sn->id,
            'method' => 'MODIFY_PURCHASE',
            'nominal' => 5000000,
            'target_purchase_id' => $purchase->id,
            'status' => 'SUBMITTED',
        ]);

        // 5. Approve Settlement
        $response = $this->post(route('purchase-return-settlements.item.approve', $settlementItem->id));
        $response->assertSessionHas('success');

        // 6. Verify Serial Number status
        $sn->refresh();
        $this->assertEquals('RETURNED', $sn->status);
        $this->assertEquals($purchaseReturn->id, $sn->purchase_return_id);

        // 7. Verify History Recorded
        $this->assertDatabaseHas('serial_number_histories', [
            'product_serial_number_id' => $sn->id,
            'event_type' => SerialNumberHistory::EVENT_PURCHASE_RETURNED,
            'location_id' => $this->location->id,
            'reference_type' => PurchaseReturnItemSettlement::class,
            'reference_id' => $settlementItem->id,
            'user_id' => $this->user->id,
        ]);
    }
}
