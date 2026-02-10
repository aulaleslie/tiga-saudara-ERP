<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Tests\TestCase;

class PurchaseShowHistoryFirstReturnedSerialTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);

        // Grant permissions
        \Illuminate\Support\Facades\Gate::define('purchases.show', fn() => true);
        \Illuminate\Support\Facades\Gate::define('purchases.archive', fn() => true);
        
        // Create dependencies
        \Modules\Setting\Entities\Setting::create(['id' => 1, 'company_name' => 'Test Company', 'company_email' => 'test@test.com', 'company_phone' => '1234567890', 'company_address' => 'Test Address', 'default_currency_id' => 1, 'default_currency_position' => 'prefix', 'notification_email' => 'test@test.com', 'footer_text' => 'Test Footer']);
        session()->put('setting_id', 1);
        
        \Modules\Setting\Entities\Unit::create(['id' => 1, 'name' => 'Unit', 'short_name' => 'U', 'operator' => '*', 'operation_value' => 1]);
        \Modules\Setting\Entities\Location::create(['id' => 1, 'name' => 'Warehouse 1', 'setting_id' => 1]);
        \Modules\People\Entities\Supplier::create(['id' => 1, 'supplier_name' => 'Test Supplier', 'supplier_phone' => '1234567890', 'supplier_email' => 'supplier@test.com', 'city' => 'Test City', 'country' => 'Test Country', 'address' => 'Test Address', 'setting_id' => 1]);
    }

    /** @test */
    public function it_shows_serial_as_returned_in_old_purchase_even_after_reuse_in_new_purchase()
    {
        // 1. Setup: Create Product
        $product = Product::create([
            'product_name' => 'Test Item',
            'product_code' => 'T-001-' . uniqid(),
            'product_quantity' => 0,
            'product_cost' => 1000,
            'product_price' => 2000,
            'serial_number_required' => 1,
            'unit_id' => 1, // Assuming seed data or nullable
            'setting_id' => session('setting_id') ?? 1,
        ]);

        // 2. Scenario: Purchase A -> Receive Serial "SN-123"
        $purchaseA = Purchase::create([
            'status' => Purchase::STATUS_APPROVED,
            'reference' => 'PR-A-' . uniqid(),
            'date' => now(),
            'due_date' => now(),
            'supplier_id' => 1, // Assuming seed data
            'setting_id' => session('setting_id') ?? 1,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'shipping_amount' => 0,
            'due_amount' => 1000,
            'discount_percentage' => 0,
            'tax_percentage' => 0,
        ]);
        
        $detailA = PurchaseDetail::create([
            'purchase_id' => $purchaseA->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_code' => $product->product_code,
            'product_name' => $product->product_name,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $receivedNoteA = ReceivedNote::create([
            'po_id' => $purchaseA->id,
            'status' => ReceivedNote::STATUS_APPROVED,
            'date' => now()->subDays(10),
            'location_id' => 1,
        ]);

        $receivedDetailA = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNoteA->id,
            'po_detail_id' => $detailA->id,
            'quantity_received' => 1,
        ]);

        // Create the serial number record initially linked to Purchase A (via ReceivedNoteDetail)
        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-123',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'received_note_detail_id' => $receivedDetailA->id,
            'location_id' => 1,
        ]);

        // Record history for Receive A
        SerialNumberHistory::create([
            'product_serial_number_id' => $serial->id,
            'event_type' => SerialNumberHistory::EVENT_RECEIVED,
            'reference_type' => ReceivedNoteDetail::class,
            'reference_id' => $receivedDetailA->id,
            'created_at' => now()->subDays(10),
            'user_id' => 1,
        ]);

        // 3. Scenario: Return "SN-123" from Purchase A
        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'RET-' . uniqid(),
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'Completed',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => session('setting_id') ?? 1,
        ]);
        
        $purchaseDetailReturn = \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'po_id' => $purchaseA->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'serial_number_ids' => [$serial->id],
        ]);
        
        $serial->update([
            'status' => ProductSerialNumber::STATUS_RETURNED,
            'purchase_return_id' => $purchaseReturn->id,
        ]);
        
        // Record history for Return
        SerialNumberHistory::create([
            'product_serial_number_id' => $serial->id,
            'event_type' => SerialNumberHistory::EVENT_PURCHASE_RETURNED,
            'reference_type' => PurchaseReturn::class, // Simplified for test
            'reference_id' => $purchaseReturn->id,
            'created_at' => now()->subDays(5),
            'user_id' => 1,
        ]);

        // 4. Scenario: Purchase B -> Re-Receive "SN-123" (Reused)
        $purchaseB = Purchase::create([
            'status' => Purchase::STATUS_APPROVED,
            'reference' => 'PR-B-' . uniqid(),
            'date' => now(),
            'due_date' => now(),
            'supplier_id' => 1,
            'setting_id' => session('setting_id') ?? 1,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'shipping_amount' => 0,
            'due_amount' => 1000,
            'discount_percentage' => 0,
            'tax_percentage' => 0,
        ]);
        
        $detailB = PurchaseDetail::create([
            'purchase_id' => $purchaseB->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_code' => $product->product_code,
            'product_name' => $product->product_name,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $receivedNoteB = ReceivedNote::create([
            'po_id' => $purchaseB->id,
            'status' => ReceivedNote::STATUS_APPROVED,
            'date' => now(),
            'location_id' => 1,
        ]);

        $receivedDetailB = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNoteB->id,
            'po_detail_id' => $detailB->id,
            'quantity_received' => 1,
        ]);

        // Update serial to point to Purchase B's receiving
        $serial->update([
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'received_note_detail_id' => $receivedDetailB->id,
            'purchase_return_id' => null, // Clear return linkage on reuse
        ]);

        // Record history for Receive B
        SerialNumberHistory::create([
            'product_serial_number_id' => $serial->id,
            'event_type' => SerialNumberHistory::EVENT_RECEIVED,
            'reference_type' => ReceivedNoteDetail::class,
            'reference_id' => $receivedDetailB->id,
            'created_at' => now(),
            'user_id' => 1,
        ]);

        // 5. Assert: View Purchase A
        $responseA = $this->get(route('purchases.show', $purchaseA->id));
        $responseA->assertOk();

        // Check that view data for received note A has the serial mapped
        $viewDataA = $responseA->viewData('receivedNotes');
        $noteA = $viewDataA->first();
        $detailA_view = $noteA->receivedNoteDetails->first();

        $this->assertTrue(
            $detailA_view->returnedSerialNumbers->contains('id', $serial->id),
            "Purchase A should show reused serial SN-123 as a returned item based on history."
        );
    }

    /** @test */
    public function it_shows_serial_as_active_in_new_purchase_after_reuse()
    {
        // 1. Setup similar to above
        $product = Product::create([
            'product_name' => 'Test Item 2',
            'product_code' => 'T-002-' . uniqid(),
            'product_quantity' => 0,
            'serial_number_required' => 1,
            'product_cost' => 100,
            'product_price' => 200,
            'unit_id' => 1,
            'setting_id' => session('setting_id') ?? 1,
        ]);
        
        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-REUSE',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'location_id' => 1,
        ]);
        
        // Purchase A (Old)
        $purchaseA = Purchase::create([
            'status' => Purchase::STATUS_APPROVED, 
            'reference' => 'PR-A2-' . uniqid(), 
            'date' => now(), 
            'due_date' => now(), 
            'supplier_id' => 1, 
            'setting_id' => session('setting_id') ?? 1, 
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'shipping_amount' => 0,
            'due_amount' => 1000,
            'discount_percentage' => 0,
            'tax_percentage' => 0,
        ]);
        
        $detailA = PurchaseDetail::create([
            'purchase_id' => $purchaseA->id, 
            'product_id' => $product->id, 
            'quantity' => 1, 
            'price' => 100, 
            'unit_price' => 100, 
            'sub_total' => 100, 
            'product_code' => $product->product_code, 
            'product_name' => $product->product_name,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        $rnA = ReceivedNote::create(['po_id' => $purchaseA->id, 'status' => ReceivedNote::STATUS_APPROVED, 'date' => now()->subDays(10), 'location_id' => 1]);
        $rndA = ReceivedNoteDetail::create(['received_note_id' => $rnA->id, 'po_detail_id' => $detailA->id, 'quantity_received' => 1]);
        
        // History A
        SerialNumberHistory::create([
            'product_serial_number_id' => $serial->id, 
            'event_type' => SerialNumberHistory::EVENT_RECEIVED, 
            'reference_type' => ReceivedNoteDetail::class, 
            'reference_id' => $rndA->id,
            'user_id' => 1,
        ]);
        
        // Return 
        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'RET-' . uniqid(),
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'Completed',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => session('setting_id') ?? 1,
        ]);
        $purchaseDetailReturn = \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'po_id' => $purchaseA->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 100,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'serial_number_ids' => [$serial->id],
        ]);
        $serial->update(['status' => ProductSerialNumber::STATUS_RETURNED, 'purchase_return_id' => $purchaseReturn->id]);
         SerialNumberHistory::create([
            'product_serial_number_id' => $serial->id, 
            'event_type' => SerialNumberHistory::EVENT_PURCHASE_RETURNED,
             'reference_type' => PurchaseReturn::class,
             'reference_id' => $purchaseReturn->id,
             'user_id' => 1, 
        ]);

        // Purchase B (New)
        $purchaseB = Purchase::create([
            'status' => Purchase::STATUS_APPROVED, 
            'reference' => 'PR-B2-' . uniqid(), 
            'date' => now(), 
            'due_date' => now(), 
            'supplier_id' => 1, 
            'setting_id' => session('setting_id') ?? 1, 
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'shipping_amount' => 0,
            'due_amount' => 1000,
            'discount_percentage' => 0,
            'tax_percentage' => 0,
        ]);
        $detailB = PurchaseDetail::create([
            'purchase_id' => $purchaseB->id, 
            'product_id' => $product->id, 
            'quantity' => 1, 
            'price' => 100, 
            'unit_price' => 100, 
            'sub_total' => 100, 
            'product_code' => $product->product_code, 
            'product_name' => $product->product_name,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);
        $rnB = ReceivedNote::create(['po_id' => $purchaseB->id, 'status' => ReceivedNote::STATUS_APPROVED, 'date' => now(), 'location_id' => 1]);
        $rndB = ReceivedNoteDetail::create(['received_note_id' => $rnB->id, 'po_detail_id' => $detailB->id, 'quantity_received' => 1]);

        // Reuse in B
        $serial->update(['status' => ProductSerialNumber::STATUS_ACTIVE, 'received_note_detail_id' => $rndB->id, 'purchase_return_id' => null]);
        
        // History B
        SerialNumberHistory::create([
            'product_serial_number_id' => $serial->id, 
            'event_type' => SerialNumberHistory::EVENT_RECEIVED, 
            'reference_type' => ReceivedNoteDetail::class, 
            'reference_id' => $rndB->id,
            'user_id' => 1,
        ]);

        // Assert View B
        $responseB = $this->get(route('purchases.show', $purchaseB->id));
        $responseB->assertOk();

        $viewDataB = $responseB->viewData('receivedNotes');
        $detailB_view = $viewDataB->first()->receivedNoteDetails->first();

        // In Purchase B, it should be in the standard active serials list, NOT returned list.
        $this->assertTrue(
            $detailB_view->productSerialNumbers->contains('id', $serial->id),
            "Purchase B should show reused serial SN-REUSE as active."
        );
        
        // Ensure it's NOT in returnedSerialNumbers for B
        $returnedSerialsB = $detailB_view->returnedSerialNumbers ?? collect([]);
        $this->assertFalse(
            $returnedSerialsB->contains('id', $serial->id),
            "Purchase B should NOT show reused serial SN-REUSE as returned."
        );
    }
}
