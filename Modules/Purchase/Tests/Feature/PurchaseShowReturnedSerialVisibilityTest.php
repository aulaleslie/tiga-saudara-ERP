<?php

namespace Modules\Purchase\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\Category;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
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
}
