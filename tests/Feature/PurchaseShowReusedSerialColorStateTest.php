<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Tests\TestCase;

class PurchaseShowReusedSerialColorStateTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);

        // Grant permissions
        \Illuminate\Support\Facades\Gate::define('purchases.show', fn() => true);
        
        // Create dependencies
        \Modules\Setting\Entities\Setting::create(['id' => 1, 'company_name' => 'Test Company', 'company_email' => 'test@test.com', 'company_phone' => '1234567890', 'company_address' => 'Test Address', 'default_currency_id' => 1, 'default_currency_position' => 'prefix', 'notification_email' => 'test@test.com', 'footer_text' => 'Test Footer']);
        session()->put('setting_id', 1);
        
        \Modules\Setting\Entities\Unit::create(['id' => 1, 'name' => 'Unit', 'short_name' => 'U', 'operator' => '*', 'operation_value' => 1]);
        \Modules\Setting\Entities\Location::create(['id' => 1, 'name' => 'Warehouse 1', 'setting_id' => 1]);
        \Modules\People\Entities\Supplier::create([
            'id' => 1,
            'supplier_name' => 'Test Supplier',
            'supplier_phone' => '1234567890',
            'supplier_email' => 'supplier@test.com',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
            'setting_id' => 1
        ]);
    }

    /** @test */
    public function it_renders_red_badge_for_returned_serials_and_info_badge_for_active_serials()
    {
        $product = Product::create([
            'product_name' => 'Test Item',
            'product_code' => 'T-001',
            'product_quantity' => 0,
            'product_cost' => 500,
            'product_price' => 1000,
            'serial_number_required' => 1,
            'setting_id' => 1,
        ]);

        $purchase = Purchase::create([
            'status' => Purchase::STATUS_APPROVED,
            'reference' => 'PR-001',
            'date' => now(),
            'due_date' => now(),
            'supplier_id' => 1,
            'setting_id' => 1,
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
            'tax_id' => null,
            'is_tax_included' => true,
        ]);
        
        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 500,
            'unit_price' => 500,
            'sub_total' => 1000,
            'product_code' => $product->product_code,
            'product_name' => $product->product_name,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        $rn = ReceivedNote::create(['po_id' => $purchase->id, 'status' => ReceivedNote::STATUS_APPROVED, 'date' => now(), 'location_id' => 1]);
        $rnd = ReceivedNoteDetail::create(['received_note_id' => $rn->id, 'po_detail_id' => $detail->id, 'quantity_received' => 2]);

        // One active, one returned
        $activeSerial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-ACTIVE',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'received_note_detail_id' => $rnd->id,
            'location_id' => 1,
        ]);
        $activeSerial->receivedNoteDetails()->attach($rnd->id);

        $returnedSerial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-RETURNED',
            'status' => ProductSerialNumber::STATUS_RETURNED,
            'received_note_detail_id' => $rnd->id,
            'location_id' => 1,
        ]);
        $returnedSerial->receivedNoteDetails()->attach($rnd->id);

        // History for both
        SerialNumberHistory::create([
            'product_serial_number_id' => $activeSerial->id,
            'event_type' => SerialNumberHistory::EVENT_RECEIVED,
            'reference_type' => ReceivedNoteDetail::class,
            'reference_id' => $rnd->id,
            'user_id' => 1,
        ]);

        SerialNumberHistory::create([
            'product_serial_number_id' => $returnedSerial->id,
            'event_type' => SerialNumberHistory::EVENT_RECEIVED,
            'reference_type' => ReceivedNoteDetail::class,
            'reference_id' => $rnd->id,
            'user_id' => 1,
        ]);

        // Mark SN-RETURNED as returned via history logic
        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'RET-001',
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 500,
            'paid_amount' => 0,
            'due_amount' => 500,
            'status' => 'Completed',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => 1,
        ]);
        
        \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'po_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 500,
            'unit_price' => 500,
            'sub_total' => 500,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'serial_number_ids' => [$returnedSerial->id],
        ]);

        SerialNumberHistory::create([
            'product_serial_number_id' => $returnedSerial->id, 
            'event_type' => SerialNumberHistory::EVENT_PURCHASE_RETURNED,
            'reference_type' => PurchaseReturn::class,
            'reference_id' => $purchaseReturn->id,
            'user_id' => 1, 
        ]);

        $response = $this->get(route('purchases.show', $purchase->id));
        $response->assertOk();

        // Check for specific badges in the HTML
        $response->assertSee('bg-info'); // Active
        $response->assertSee('SN-ACTIVE');
        $response->assertSee('bg-danger'); // Returned
        $response->assertSee('SN-RETURNED');
    }

    /** @test */
    public function it_does_not_duplicate_badges_if_serial_is_in_both_direct_and_returned_collections()
    {
        $product = Product::create([
            'product_name' => 'Test Item',
            'product_code' => 'T-002',
            'product_quantity' => 0,
            'product_cost' => 500,
            'product_price' => 1000,
            'serial_number_required' => 1,
            'setting_id' => 1,
        ]);

        $purchase = Purchase::create([
            'status' => Purchase::STATUS_APPROVED,
            'reference' => 'PR-002',
            'date' => now(),
            'due_date' => now(),
            'supplier_id' => 1,
            'setting_id' => 1,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 500,
            'paid_amount' => 0,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'shipping_amount' => 0,
            'due_amount' => 500,
            'discount_percentage' => 0,
            'tax_percentage' => 0,
            'tax_id' => null,
            'is_tax_included' => true,
        ]);
        
        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 500,
            'unit_price' => 500,
            'sub_total' => 500,
            'product_code' => $product->product_code,
            'product_name' => $product->product_name,
            'product_discount_type' => 'fixed',
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'tax_id' => null,
        ]);

        $rn = ReceivedNote::create(['po_id' => $purchase->id, 'status' => ReceivedNote::STATUS_APPROVED, 'date' => now(), 'location_id' => 1]);
        $rnd = ReceivedNoteDetail::create(['received_note_id' => $rn->id, 'po_detail_id' => $detail->id, 'quantity_received' => 1]);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-DUPE-TEST',
            'status' => ProductSerialNumber::STATUS_RETURNED, // Direct relation will find it
            'received_note_detail_id' => $rnd->id,
            'location_id' => 1,
        ]);
        $serial->receivedNoteDetails()->attach($rnd->id);

        // History entry (Returned mapping will also find it)
        SerialNumberHistory::create([
            'product_serial_number_id' => $serial->id,
            'event_type' => SerialNumberHistory::EVENT_RECEIVED,
            'reference_type' => ReceivedNoteDetail::class,
            'reference_id' => $rnd->id,
            'user_id' => 1,
        ]);

        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'RET-002',
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 500,
            'paid_amount' => 0,
            'due_amount' => 500,
            'status' => 'Completed',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => 1,
        ]);
        
        \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'po_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 500,
            'unit_price' => 500,
            'sub_total' => 500,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'serial_number_ids' => [$serial->id],
        ]);

        SerialNumberHistory::create([
            'product_serial_number_id' => $serial->id, 
            'event_type' => SerialNumberHistory::EVENT_PURCHASE_RETURNED,
            'reference_type' => PurchaseReturn::class,
            'reference_id' => $purchaseReturn->id,
            'user_id' => 1, 
        ]);

        $response = $this->get(route('purchases.show', $purchase->id));
        $response->assertOk();

        // Count occurrences of the serial number in the response
        $content = $response->getContent();
        $count = substr_count($content, 'SN-DUPE-TEST');
        
        $this->assertEquals(1, $count, "Serial number should only appear once even if it is in both direct and returned collections.");
    }
}
