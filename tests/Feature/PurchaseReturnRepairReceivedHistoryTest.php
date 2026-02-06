<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
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

class PurchaseReturnRepairReceivedHistoryTest extends TestCase
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

        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'purchaseReturnSettlements.receive']);
        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['purchaseReturnSettlements.receive']);
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

    private function setupAwaitingReceiveSettlement()
    {
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

        $sn = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-OLD-001',
            'status' => 'active',
            'is_in_return_process' => true,
            'purchase_return_id' => $purchaseReturn->id
        ]);

        $settlementItem = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $purchaseReturn->id,
            'purchase_return_detail_id' => $returnDetail->id,
            'product_serial_number_id' => $sn->id,
            'method' => 'PRODUCT_REPAIR',
            'nominal' => 5000000,
            'status' => PurchaseReturnItemSettlement::STATUS_APPROVED_AWAITING_RECEIVE,
        ]);

        return [$purchaseReturn, $settlementItem, $sn];
    }

    public function test_receiving_repair_with_same_serial_records_repair_received_history()
    {
        [$purchaseReturn, $settlementItem, $sn] = $this->setupAwaitingReceiveSettlement();

        $response = $this->post(route('purchase-return-settlements.item.receive', $settlementItem->id), [
            'location_id' => $this->location->id,
            'received_quantity' => 1,
            'replacement_serial_number' => $sn->serial_number,
            'note' => 'Test same serial'
        ]);

        $response->assertSessionHas('success');

        $this->assertDatabaseHas('serial_number_histories', [
            'product_serial_number_id' => $sn->id,
            'event_type' => SerialNumberHistory::EVENT_REPAIR_RECEIVED,
            'location_id' => $this->location->id,
            'reference_type' => PurchaseReturnItemSettlement::class,
            'reference_id' => $settlementItem->id,
        ]);
    }

    public function test_receiving_repair_with_new_serial_records_repair_received_history()
    {
        [$purchaseReturn, $settlementItem, $sn] = $this->setupAwaitingReceiveSettlement();

        $newSN = 'SN-NEW-999';

        $response = $this->post(route('purchase-return-settlements.item.receive', $settlementItem->id), [
            'location_id' => $this->location->id,
            'received_quantity' => 1,
            'replacement_serial_number' => $newSN,
            'note' => 'Test new serial'
        ]);

        $response->assertSessionHas('success');

        $createdSN = ProductSerialNumber::where('serial_number', $newSN)->first();
        $this->assertNotNull($createdSN);

        $this->assertDatabaseHas('serial_number_histories', [
            'product_serial_number_id' => $createdSN->id,
            'event_type' => SerialNumberHistory::EVENT_REPAIR_RECEIVED,
            'location_id' => $this->location->id,
            'reference_type' => PurchaseReturnItemSettlement::class,
            'reference_id' => $settlementItem->id,
        ]);
    }

    public function test_receiving_repair_with_returned_serial_records_repair_received_history()
    {
        [$purchaseReturn, $settlementItem, $sn] = $this->setupAwaitingReceiveSettlement();

        $returnedSN = 'SN-RETURNED-888';
        $existingReturned = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => $returnedSN,
            'status' => 'returned',
            'is_in_return_process' => false,
        ]);

        $response = $this->post(route('purchase-return-settlements.item.receive', $settlementItem->id), [
            'location_id' => $this->location->id,
            'received_quantity' => 1,
            'replacement_serial_number' => $returnedSN,
            'note' => 'Test returned serial replacement'
        ]);

        $response->assertSessionHas('success');

        $this->assertDatabaseHas('serial_number_histories', [
            'product_serial_number_id' => $existingReturned->id,
            'event_type' => SerialNumberHistory::EVENT_REPAIR_RECEIVED,
            'location_id' => $this->location->id,
            'reference_type' => PurchaseReturnItemSettlement::class,
            'reference_id' => $settlementItem->id,
        ]);
        
        $existingReturned->refresh();
        $this->assertEquals('ACTIVE', strtoupper($existingReturned->status));
    }
}
