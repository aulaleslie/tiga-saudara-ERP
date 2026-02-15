<?php

namespace Modules\Purchase\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\SerialNumberHistory;
use Modules\Product\Entities\Category;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Unit;
use Tests\TestCase;
use Spatie\Permission\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PurchaseShowReturnedSerialVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;
    protected $supplier;
    protected $category;
    protected $unit;
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Seed necessary data
        Currency::create([
             'id' => 1,
             'currency_name' => 'Rupiah',
             'code' => 'IDR',
             'symbol' => 'Rp',
             'thousand_separator' => '.',
             'decimal_separator' => ',',
             'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'id' => 1,
            'company_name' => 'Test Company',
            'company_email' => 'test@company.com',
            'company_phone' => '123456',
            'notification_email' => 'notify@company.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);
        
        session(['setting_id' => $this->setting->id]);

        $this->user = User::factory()->create();

        $this->supplier = Supplier::create([
            'id' => 1,
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@example.com',
            'supplier_phone' => '12345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Test Address',
            'setting_id' => $this->setting->id,
        ]);

        // Disable foreign keys during setup to be safe
        DB::statement('PRAGMA foreign_keys = OFF');

        $this->category = Category::create([
            'id' => 1,
            'category_code' => 'CAT01',
            'category_name' => 'Test Category',
            'setting_id' => $this->setting->id,
            'created_by' => $this->user->id,
        ]);

        $this->unit = Unit::create([
            'id' => 1,
            'operator' => '*',
            'operation_value' => 1,
            'short_name' => 'pc',
            'name' => 'Piece',
            'setting_id' => $this->setting->id,
        ]);

        $this->actingAsAdmin();
    }

    protected function actingAsAdmin()
    {
        Permission::findOrCreate('purchases.show', 'web');
        $this->user->givePermissionTo('purchases.show');
        $this->actingAs($this->user);
    }

    protected function createProduct($name = 'Test Product', $code = 'P001')
    {
        return Product::create([
            'product_name' => $name,
            'product_code' => $code,
            'product_unit' => 'pc',
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_quantity' => 0,
            'product_stock_alert' => 1,
            'setting_id' => $this->setting->id,
            'category_id' => 1,
            'product_barcode_symbology' => 'C128',
            'unit_id' => 1,
            'stock_managed' => 1,
        ]);
    }

    public function test_returned_serial_appears_in_purchase_show_with_red_pill()
    {
        // 1. Create Purchase
        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-001',
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => $this->setting->id,
        ]);

        $product = $this->createProduct('Product Returned', 'PR001');
        
        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id'  => $product->id,
            'product_name'=> $product->product_name,
            'product_code'=> $product->product_code,
            'quantity'    => 1,
            'price'       => 1000,
            'unit_price'  => 1000,
            'sub_total'   => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // 2. Create Receiving
        $receivedNote = ReceivedNote::create([
            'po_id'       => $purchase->id,
            'status'      => ReceivedNote::STATUS_APPROVED,
            'location_id' => 1,
            'setting_id'  => $this->setting->id,
            'date' => now(),
            'external_delivery_number' => 'DN-001',
        ]);

        $receivedNoteDetail = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id'     => $purchaseDetail->id,
            'quantity_received'=> 1,
        ]);

        // 3. Create Serial
        $serial = ProductSerialNumber::create([
            'product_id'              => $product->id,
            'serial_number'           => 'SN-RETURN-TEST',
            'status'                  => ProductSerialNumber::STATUS_ACTIVE,
            'received_note_detail_id' => $receivedNoteDetail->id,
            'location_id'             => 1,
        ]);

        // 4. Create Purchase Return and mark serial as returned (simulating settlement)
        $purchaseReturn = PurchaseReturn::create([
            'date'        => now(),
            'reference'   => 'PR-001',
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'status'      => 'completed',
            'total_amount'=> 1000,
            'paid_amount' => 0,
            'due_amount'  => 1000,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id'  => $this->setting->id,
        ]);

        // Create return detail to link to PO
        \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'po_id' => $purchase->id,
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

        // Manually record history to simulate what happens during receiving
        \Modules\Product\Entities\SerialNumberHistory::create([
            'product_serial_number_id' => $serial->id,
            'event_type' => \Modules\Product\Entities\SerialNumberHistory::EVENT_RECEIVED,
            'location_id' => 1,
            'reference_type' => \Modules\Purchase\Entities\ReceivedNoteDetail::class,
            'reference_id' => $receivedNoteDetail->id,
            'user_id' => $this->user->id,
        ]);

        // Record history for return
        \Modules\Product\Entities\SerialNumberHistory::create([
            'product_serial_number_id' => $serial->id,
            'event_type' => \Modules\Product\Entities\SerialNumberHistory::EVENT_PURCHASE_RETURNED,
            'location_id' => 1,
            'reference_type' => \Modules\PurchasesReturn\Entities\PurchaseReturn::class,
            'reference_id' => $purchaseReturn->id,
            'user_id' => $this->user->id,
        ]);

        $serial->update([
            'status'                  => ProductSerialNumber::STATUS_RETURNED,
            'received_note_detail_id' => null, // This is what settlement does
            'purchase_return_id'      => $purchaseReturn->id,
        ]);

        // 5. Visit Purchase Show
        $response = $this->get(route('purchases.show', $purchase->id));

        $response->assertStatus(200);
        
        // 6. Assertions
        $response->assertSee('SN-RETURN-TEST');
        $response->assertSee('bg-danger'); // Red pill
    }

    public function test_active_serial_appears_in_purchase_show_with_info_pill()
    {
         // 1. Create Purchase
        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-002',
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => $this->setting->id,
        ]);

        $product = $this->createProduct('Product Active', 'PA001');
        
        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id'  => $product->id,
            'product_name'=> $product->product_name,
            'product_code'=> $product->product_code,
            'quantity'    => 1,
            'price'       => 1000,
            'unit_price'  => 1000,
            'sub_total'   => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // 2. Create Receiving
        $receivedNote = ReceivedNote::create([
            'po_id'       => $purchase->id,
            'status'      => ReceivedNote::STATUS_APPROVED,
            'location_id' => 1,
            'setting_id'  => $this->setting->id,
            'date' => now(),
            'external_delivery_number' => 'DN-002',
        ]);

        $receivedNoteDetail = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id'     => $purchaseDetail->id,
            'quantity_received'=> 1,
        ]);

        // 3. Create Serial
        $serial = ProductSerialNumber::create([
            'product_id'              => $product->id,
            'serial_number'           => 'SN-ACTIVE-TEST',
            'status'                  => ProductSerialNumber::STATUS_ACTIVE,
            'received_note_detail_id' => $receivedNoteDetail->id,
            'location_id'             => 1,
        ]);

        // 4. Visit Purchase Show
        $response = $this->get(route('purchases.show', $purchase->id));

        $response->assertStatus(200);
        
        // 5. Assertions
        $response->assertSee('SN-ACTIVE-TEST');
        $response->assertSee('bg-info'); 
        // We don't assertDontSee('bg-danger') because it's in the over-receive modal
    }

    public function test_return_in_process_serial_appears_in_purchase_show_with_warning_pill()
    {
        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-003',
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => $this->setting->id,
        ]);

        $product = $this->createProduct('Product In Process', 'PIP001');

        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id'  => $product->id,
            'product_name'=> $product->product_name,
            'product_code'=> $product->product_code,
            'quantity'    => 1,
            'price'       => 1000,
            'unit_price'  => 1000,
            'sub_total'   => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $receivedNote = ReceivedNote::create([
            'po_id'       => $purchase->id,
            'status'      => ReceivedNote::STATUS_APPROVED,
            'location_id' => 1,
            'setting_id'  => $this->setting->id,
            'date' => now(),
            'external_delivery_number' => 'DN-003',
        ]);

        $receivedNoteDetail = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id'     => $purchaseDetail->id,
            'quantity_received'=> 1,
        ]);

        ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-IN-PROCESS-TEST',
            'status' => ProductSerialNumber::STATUS_RETURN_IN_PROCESS,
            'is_in_return_process' => true,
            'received_note_detail_id' => $receivedNoteDetail->id,
            'location_id' => 1,
        ]);

        $response = $this->get(route('purchases.show', $purchase->id));

        $response->assertStatus(200);
        $response->assertSee('SN-IN-PROCESS-TEST');
        $response->assertSee('badge bg-warning text-dark');
    }

    public function test_returned_serial_without_purchase_returned_history_uses_settlement_fallback_for_red_pill()
    {
        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-FALLBACK-001',
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => $this->setting->id,
        ]);

        $product = $this->createProduct('Product Fallback Returned', 'PFR001');

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
            'product_tax_amount' => 0,
        ]);

        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'status' => ReceivedNote::STATUS_APPROVED,
            'location_id' => 1,
            'setting_id' => $this->setting->id,
            'date' => now(),
            'external_delivery_number' => 'DN-FALLBACK-001',
        ]);

        $receivedNoteDetail = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 1,
        ]);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-RETURNED-FALLBACK',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'received_note_detail_id' => $receivedNoteDetail->id,
            'location_id' => 1,
        ]);

        SerialNumberHistory::create([
            'product_serial_number_id' => $serial->id,
            'event_type' => SerialNumberHistory::EVENT_RECEIVED,
            'location_id' => 1,
            'reference_type' => ReceivedNoteDetail::class,
            'reference_id' => $receivedNoteDetail->id,
            'user_id' => $this->user->id,
        ]);

        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PR-FALLBACK-001',
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'status' => 'Completed',
            'approval_status' => 'Approved',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ]);

        $returnDetail = \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'po_id' => null,
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

        PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $purchaseReturn->id,
            'purchase_return_detail_id' => $returnDetail->id,
            'product_serial_number_id' => $serial->id,
            'method' => 'MODIFY_PURCHASE',
            'status' => PurchaseReturnItemSettlement::STATUS_APPROVED,
            'target_purchase_id' => $purchase->id,
            'nominal' => 1000,
        ]);

        $serial->update([
            'status' => ProductSerialNumber::STATUS_RETURNED,
            'received_note_detail_id' => null,
            'purchase_return_id' => $purchaseReturn->id,
        ]);

        $response = $this->get(route('purchases.show', $purchase->id));

        $response->assertStatus(200);
        $response->assertSee('SN-RETURNED-FALLBACK');
        $response->assertSee('bg-danger');
    }

    public function test_purchase_show_keeps_source_purchase_red_and_destination_purchase_blue_for_cross_purchase_reuse()
    {
        $product = $this->createProduct('Cross Purchase Reuse Product', 'CPR001');

        $purchaseX = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-CROSS-X-001',
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => $this->setting->id,
        ]);

        $purchaseY = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-CROSS-Y-001',
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'setting_id' => $this->setting->id,
        ]);

        $purchaseDetailX = PurchaseDetail::create([
            'purchase_id' => $purchaseX->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $purchaseDetailY = PurchaseDetail::create([
            'purchase_id' => $purchaseY->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 2000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $receivedNoteX = ReceivedNote::create([
            'po_id' => $purchaseX->id,
            'status' => ReceivedNote::STATUS_APPROVED,
            'location_id' => 1,
            'setting_id' => $this->setting->id,
            'date' => now(),
            'external_delivery_number' => 'DN-CROSS-X-001',
        ]);

        $receivedNoteY = ReceivedNote::create([
            'po_id' => $purchaseY->id,
            'status' => ReceivedNote::STATUS_APPROVED,
            'location_id' => 1,
            'setting_id' => $this->setting->id,
            'date' => now(),
            'external_delivery_number' => 'DN-CROSS-Y-001',
        ]);

        $receivedDetailX = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNoteX->id,
            'po_detail_id' => $purchaseDetailX->id,
            'quantity_received' => 1,
        ]);

        $receivedDetailY = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNoteY->id,
            'po_detail_id' => $purchaseDetailY->id,
            'quantity_received' => 2,
        ]);

        $serialA = ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-A-CROSS-REUSE',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'received_note_detail_id' => $receivedDetailX->id,
            'location_id' => 1,
        ]);

        $serialB = ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-B-CROSS-RETURNED',
            'status' => ProductSerialNumber::STATUS_RETURNED,
            'received_note_detail_id' => $receivedDetailY->id,
            'location_id' => 1,
        ]);

        SerialNumberHistory::create([
            'product_serial_number_id' => $serialA->id,
            'event_type' => SerialNumberHistory::EVENT_RECEIVED,
            'location_id' => 1,
            'reference_type' => ReceivedNoteDetail::class,
            'reference_id' => $receivedDetailX->id,
            'user_id' => $this->user->id,
        ]);

        SerialNumberHistory::create([
            'product_serial_number_id' => $serialB->id,
            'event_type' => SerialNumberHistory::EVENT_RECEIVED,
            'location_id' => 1,
            'reference_type' => ReceivedNoteDetail::class,
            'reference_id' => $receivedDetailY->id,
            'user_id' => $this->user->id,
        ]);

        // Historical returned marker on purchase X (fallback path), then serial A is reused and moved to purchase Y.
        $purchaseReturnX = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PR-CROSS-X-001',
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'status' => 'Completed',
            'approval_status' => 'Approved',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ]);

        $returnDetailX = \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturnX->id,
            'po_id' => $purchaseX->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'serial_number_ids' => [$serialA->id],
        ]);

        PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $purchaseReturnX->id,
            'purchase_return_detail_id' => $returnDetailX->id,
            'product_serial_number_id' => $serialA->id,
            'method' => 'MODIFY_PURCHASE',
            'status' => PurchaseReturnItemSettlement::STATUS_APPROVED,
            'target_purchase_id' => $purchaseX->id,
            'nominal' => 1000,
        ]);

        $purchaseReturnY = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PR-CROSS-Y-001',
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'status' => 'Completed',
            'approval_status' => 'Approved',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'setting_id' => $this->setting->id,
        ]);

        \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturnY->id,
            'po_id' => $purchaseY->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'serial_number_ids' => [$serialB->id],
        ]);

        SerialNumberHistory::create([
            'product_serial_number_id' => $serialB->id,
            'event_type' => SerialNumberHistory::EVENT_PURCHASE_RETURNED,
            'location_id' => 1,
            'reference_type' => PurchaseReturn::class,
            'reference_id' => $purchaseReturnY->id,
            'user_id' => $this->user->id,
        ]);

        $serialA->update([
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'received_note_detail_id' => $receivedDetailY->id,
            'purchase_return_id' => null,
            'location_id' => 1,
        ]);

        $serialB->update([
            'status' => ProductSerialNumber::STATUS_RETURNED,
            'received_note_detail_id' => $receivedDetailY->id,
            'purchase_return_id' => $purchaseReturnY->id,
            'location_id' => 1,
        ]);

        $responseX = $this->get(route('purchases.show', $purchaseX->id));
        $responseX->assertStatus(200);
        $responseX->assertSee('SN-A-CROSS-REUSE');
        $responseX->assertSee('bg-danger');

        $responseY = $this->get(route('purchases.show', $purchaseY->id));
        $responseY->assertStatus(200);
        $responseY->assertSee('SN-A-CROSS-REUSE');
        $responseY->assertSee('SN-B-CROSS-RETURNED');
        $responseY->assertSee('bg-info');
        $responseY->assertSee('bg-danger');
    }

    public function test_purchase_show_displays_old_serial_red_and_reused_replacement_blue_for_same_purchase()
    {
        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-REPAIR-REUSE-001',
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'setting_id' => $this->setting->id,
        ]);

        $product = $this->createProduct('Repair Reuse Product', 'RRP001');

        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 2000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $receivedNote = ReceivedNote::create([
            'po_id' => $purchase->id,
            'status' => ReceivedNote::STATUS_APPROVED,
            'location_id' => 1,
            'setting_id' => $this->setting->id,
            'date' => now(),
            'external_delivery_number' => 'DN-REPAIR-REUSE-001',
        ]);

        $receivedNoteDetail = ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'po_detail_id' => $purchaseDetail->id,
            'quantity_received' => 2,
        ]);

        $replacementSerial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-A-REUSED-BLUE',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'received_note_detail_id' => $receivedNoteDetail->id,
            'location_id' => 1,
        ]);

        $oldReturnedSerial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-B-OLD-RED',
            'status' => ProductSerialNumber::STATUS_RETURNED,
            'received_note_detail_id' => $receivedNoteDetail->id,
            'location_id' => 1,
        ]);

        SerialNumberHistory::create([
            'product_serial_number_id' => $replacementSerial->id,
            'event_type' => SerialNumberHistory::EVENT_RECEIVED,
            'location_id' => 1,
            'reference_type' => ReceivedNoteDetail::class,
            'reference_id' => $receivedNoteDetail->id,
            'user_id' => $this->user->id,
        ]);

        SerialNumberHistory::create([
            'product_serial_number_id' => $oldReturnedSerial->id,
            'event_type' => SerialNumberHistory::EVENT_RECEIVED,
            'location_id' => 1,
            'reference_type' => ReceivedNoteDetail::class,
            'reference_id' => $receivedNoteDetail->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->get(route('purchases.show', $purchase->id));

        $response->assertStatus(200);
        $response->assertSeeInOrder(['SN-A-REUSED-BLUE', 'SN-B-OLD-RED']);
        $response->assertSee('bg-info');
        $response->assertSee('bg-danger');
    }
}
