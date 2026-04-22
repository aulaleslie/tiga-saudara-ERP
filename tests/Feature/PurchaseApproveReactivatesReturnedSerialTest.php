<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Category;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;

class PurchaseApproveReactivatesReturnedSerialTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    protected $setting;
    protected $unit;
    protected $category;
    protected $location;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->withoutMiddleware(\App\Http\Middleware\CheckUserRoleForSetting::class);

        // Create dependencies manually
        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->user = \App\Models\User::factory()->create();
        $this->actingAs($this->user);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'company@example.com',
            'company_phone' => '0800000000',
            'site_logo' => null,
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'left',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'company_address' => 'Street 1',
        ]);

        $this->unit = Unit::create([
            'name' => 'PCS',
            'short_name' => 'PCS',
            'operator' => '*',
            'operation_value' => 1,
        ]);

        $this->category = Category::create([
            'created_by' => $this->user->id,
            'category_code' => 'CAT-01',
            'category_name' => 'Category',
            'setting_id' => $this->setting->id,
        ]);

        $this->location = \Modules\Setting\Entities\Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Test Location',
        ]);
        
        // Set session for controller usage
        session(['setting_id' => $this->setting->id]);
        
        // Mock permissions - explicitly allow
        \Illuminate\Support\Facades\Gate::define('purchases.receive.approval', fn() => true);
    }

    private function createProduct()
    {
        return Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'product_name' => 'Sample Product ' . uniqid(),
            'product_code' => 'PRD-' . uniqid(),
            'product_barcode_symbology' => null,
            'product_quantity' => 0,
            'product_cost' => 500,
            'product_price' => 1000,
            'product_unit' => 'PCS',
            'product_stock_alert' => 5,
            'product_order_tax' => 0,
            'product_tax_type' => 0,
            'stock_managed' => true,
            'unit_id' => $this->unit->id,
            'base_unit_id' => $this->unit->id,
            'sale_price' => 1000,
            'tier_1_price' => 1000,
            'tier_2_price' => 1000,
            'serial_number_required' => 1,
        ]);
    }

    /** @test */
    public function it_reactivates_existing_returned_serial_row_instead_of_duplicating()
    {
        // 1. Given a product with a serial number 'SN-123' that is 'RETURNED'
        $product = $this->createProduct();
        $serialNumber = 'SN-123';
        
        $returnedSerial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => $serialNumber,
            'status' => ProductSerialNumber::STATUS_RETURNED,
            'is_in_return_process' => false,
            'location_id' => $this->location->id,
            'purchase_return_id' => 999, // Should be cleared
        ]);

        // 2. Create a new Purchase and Receiving for the same product and serial
        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now(),
            'supplier_id' => \Modules\People\Entities\Supplier::create([
                'supplier_name' => 'Sup', 
                'supplier_phone' => '000', 
                'city' => 'C', 
                'address' => 'A', 
                'supplier_email' => 'sup@test.com', // Added
                'country' => 'Indonesia', // Added
                'setting_id' => $this->setting->id
            ])->id,
            'supplier_purchase_number' => 'SUP-002',
            'tax_ref_no' => 'TAX-002',
            'total_amount' => 1000,
            'due_amount' => 1000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'setting_id' => $this->setting->id,
            'paid_amount' => 0,
            'is_tax_included' => false,
            'payment_method' => '',
            // 'reference' => 'PO-002', // Helper might auto-generate or we can set it
        ]); // helper boot might set ref if omitted, but let's see. Purchase model usually handles it.

        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null
        ]);

        $receivedNote = ReceivedNote::create([
            'date' => now(),
            'status' => ReceivedNote::STATUS_PENDING,
            'po_id' => $purchase->id,
            'location_id' => $this->location->id,
        ]);

        ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'quantity_received' => 1,
            'po_detail_id' => $purchaseDetail->id,
            'pending_serial_numbers' => [$serialNumber],
        ]);

        // 3. Approve the receiving
        // Bypass permission check by mocking? Or just assume actingAs(user) has all access?
        // Let's assume standard flow works. If permission fails, we'll see 403.
        // We need to bypass Gate::denies('purchases.receive.approval')
        // We can use partial mock or just grant permission if using Spatie permissions, but here it seems simple Gates.
        // Let's rely on standard actingAs user. If it fails, we'll strip middleware or mock gate.

        $response = $this->post(route('receivings.approve', $receivedNote->id));

        // 4. Assert Success
        if ($response->status() === 403) {
           $this->fail('Permission denied on approval. Check Gate configuration.');
        }
        
        $response->assertRedirect();
        
        // 5. Assert Database State
        // Should still be only ONE row for this serial
        $this->assertDatabaseCount('product_serial_numbers', 1);
        
        $updatedSerial = $returnedSerial->fresh();
        
        // Status should be ACTIVE
        $this->assertEquals(ProductSerialNumber::STATUS_ACTIVE, $updatedSerial->status);
        
        // Return markers should be cleared
        $this->assertNull($updatedSerial->purchase_return_id);
        $this->assertFalse($updatedSerial->is_in_return_process);
        
        // Should track new Location (if changed) - we kept it same, but logic updates strict overwrite
        $this->assertEquals($this->location->id, $updatedSerial->location_id);
    }

    /** @test */
    public function it_records_history_on_reactivation()
    {
        // 1. Setup returned serial
        $product = $this->createProduct();
        $serialNumber = 'SN-HIST-1';
        $returnedSerial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => $serialNumber,
            'status' => ProductSerialNumber::STATUS_RETURNED,
            'location_id' => $this->location->id,
        ]);

        // 2. Setup Purchase/Receiving
        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now(),
            'supplier_id' => \Modules\People\Entities\Supplier::create([
                'supplier_name' => 'Sup2', 
                'supplier_phone' => '001', 
                'city' => 'C', 
                'address' => 'A', 
                'supplier_email' => 'sup2@test.com',
                'country' => 'Indonesia', // Added
                'setting_id' => $this->setting->id
            ])->id,
            'supplier_purchase_number' => 'SUP-003',
            'tax_ref_no' => 'TAX-003',
            'total_amount' => 100,
            'due_amount' => 100,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'setting_id' => $this->setting->id,
            'paid_amount' => 0,
            'is_tax_included' => false,
            'payment_method' => '',
            // 'reference' => 'PO-H', 
        ]);
        
        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id, 
            'product_id' => $product->id, 
            'quantity' => 1, 
            'price' => 100, 
            'unit_price' => 100, 
            'sub_total' => 100, 
            'product_name' => $product->product_name, 
            'product_code' => $product->product_code,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'tax_id' => null
        ]);
        
        $receivedNote = ReceivedNote::create(['po_id' => $purchase->id, 'status' => ReceivedNote::STATUS_PENDING, 'location_id' => $this->location->id, 'date' => now()]);
        ReceivedNoteDetail::create(['received_note_id' => $receivedNote->id, 'po_detail_id' => $purchaseDetail->id, 'quantity_received' => 1, 'pending_serial_numbers' => [$serialNumber]]);

        // 3. Approve
        $this->post(route('receivings.approve', $receivedNote->id));

        // 4. Assert History
        // We expect a new history entry for EVENT_RECEIVED on this ID
        $this->assertDatabaseHas('serial_number_histories', [
            'product_serial_number_id' => $returnedSerial->id,
            'event_type' => SerialNumberHistory::EVENT_RECEIVED,
            // 'reference_type' => ReceivedNoteDetail::class // checking type usually reliably
        ]);
        
        // Count history - assuming we started with 0 or 1.
        // The service adds one.
        $this->assertEquals(1, SerialNumberHistory::where('product_serial_number_id', $returnedSerial->id)->where('event_type', SerialNumberHistory::EVENT_RECEIVED)->count());
    }

    /** @test */
    public function it_throws_exception_on_conflict_with_active_serial()
    {
        // 1. Setup ACTIVE serial
        $product = $this->createProduct();
        $serialNumber = 'SN-ACTIVE-1';
        $activeSerial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => $serialNumber,
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'location_id' => $this->location->id,
        ]);

        // 2. Setup Purchase/Receiving
        $purchase = Purchase::create([
            'date' => now(), 'due_date' => now(),
            'supplier_id' => \Modules\People\Entities\Supplier::create(['supplier_name' => 'Sup3', 'supplier_phone' => '002', 'city' => 'C', 'address' => 'A', 'supplier_email' => 'sup3@test.com', 'country' => 'ID', 'setting_id' => $this->setting->id])->id,
            'reference' => 'PO-C', 'total_amount' => 100, 'due_amount' => 100, 'paid_amount' => 0, 'setting_id' => $this->setting->id, 'supplier_purchase_number' => 'S3', 'tax_ref_no' => 'T3',
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => '',
            'is_tax_included' => false,
        ]);
        $purchaseDetail = PurchaseDetail::create(['purchase_id' => $purchase->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 100, 'unit_price' => 100, 'sub_total' => 100, 'product_name' => $product->product_name, 'product_code' => $product->product_code, 'product_discount_amount' => 0, 'product_discount_type' => 'fixed', 'product_tax_amount' => 0]);
        $receivedNote = ReceivedNote::create(['po_id' => $purchase->id, 'status' => ReceivedNote::STATUS_PENDING, 'location_id' => $this->location->id, 'date' => now()]);
        ReceivedNoteDetail::create(['received_note_id' => $receivedNote->id, 'po_detail_id' => $purchaseDetail->id, 'quantity_received' => 1, 'pending_serial_numbers' => [$serialNumber]]);

        // 3. Approve - Expect Conflict (409)
        $response = $this->post(route('receivings.approve', $receivedNote->id));

        $response->assertStatus(409);
        $this->assertEquals("Serial number {$serialNumber} sudah ada dan statusnya bukan RETURNED atau SOLD.", $response->getContent());
    }
}
