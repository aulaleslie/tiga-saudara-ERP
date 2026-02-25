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
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Unit;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;
use Spatie\Permission\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class ReceiveSoldSerialNumberTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;
    protected $user;

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
        
        Permission::findOrCreate('purchases.receive', 'web');
        Permission::findOrCreate('purchaseReceivings.approval', 'web');
        $this->user->givePermissionTo(['purchases.receive', 'purchaseReceivings.approval']);
        
        $this->actingAs($this->user);

        DB::statement('PRAGMA foreign_keys = OFF');

        Category::create([
            'id' => 1,
            'category_code' => 'CAT01',
            'category_name' => 'Test Category',
            'setting_id' => $this->setting->id,
            'created_by' => $this->user->id,
        ]);

        Unit::create([
            'id' => 1,
            'operator' => '*',
            'operation_value' => 1,
            'short_name' => 'pc',
            'name' => 'Piece',
            'setting_id' => $this->setting->id,
        ]);

        \Modules\Setting\Entities\Location::create([
            'id' => 1,
            'name' => 'Test Location',
            'setting_id' => $this->setting->id,
        ]);
    }

    public function test_can_use_sold_serial_as_replacement_in_settlement()
    {
        Permission::findOrCreate('purchaseReturnSettlements.receive', 'web');
        $this->user->givePermissionTo('purchaseReturnSettlements.receive');

        // 1. Create Product and a SOLD Serial (Replacement)
        $product = Product::create([
            'product_name' => 'Settlement Product',
            'product_code' => 'SEP001',
            'product_unit' => 'pc',
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_quantity' => 1,
            'setting_id' => $this->setting->id,
            'category_id' => 1,
            'unit_id' => 1,
            'stock_managed' => 1,
            'serial_number_required' => 1,
        ]);

        $soldSerial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => 1,
            'serial_number' => 'SN-SOLD-REPLACEMENT',
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        // 2. Create another Serial that is being returned
        $returningSerial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => 1,
            'serial_number' => 'SN-RETURNING',
            'status' => ProductSerialNumber::STATUS_RETURN_IN_PROCESS,
            'is_in_return_process' => true,
        ]);

        // 3. Create Purchase Return
        $purchaseReturn = \Modules\PurchasesReturn\Entities\PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PRRN-2026-02-00001',
            'supplier_id' => 1,
            'supplier_name' => 'Supplier A',
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'payment_method' => 'cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'setting_id' => $this->setting->id,
            'location_id' => 1,
        ]);

        $returnDetail = \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'serial_number_ids' => [$returningSerial->id],
        ]);

        // 4. Create Settlement Record (Awaiting Receive)
        $itemSettlement = \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $purchaseReturn->id,
            'purchase_return_detail_id' => $returnDetail->id,
            'product_serial_number_id' => $returningSerial->id,
            'method' => 'PRODUCT_REPAIR',
            'status' => \Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::STATUS_APPROVED_AWAITING_RECEIVE,
            'nominal' => 1000,
        ]);

        // 5. Submit Receive (PRODUCT_REPAIR with replacement serial)
        $response = $this->post(route('purchase-return-settlements.item.receive', $itemSettlement->id), [
            'location_id' => 1,
            'received_quantity' => 1,
            'replacement_serial_number' => 'SN-SOLD-REPLACEMENT',
            'note' => 'Fixed with sold serial',
        ]);

        if (session('error')) {
            dump(session('error'));
        }
        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        
        // 6. Assertions
        $itemSettlement->refresh();
        $this->assertEquals(\Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement::STATUS_RECEIVED, $itemSettlement->status);
        $this->assertNotNull($itemSettlement->replacement_serial_number_id);
        
        $replacementSerial = ProductSerialNumber::find($itemSettlement->replacement_serial_number_id);
        $this->assertEquals('SN-SOLD-REPLACEMENT', $replacementSerial->serial_number);
        $this->assertEquals(ProductSerialNumber::STATUS_ACTIVE, $replacementSerial->status);
    }

    public function test_can_receive_sold_serial_number()
    {
        // 1. Create Product with SOLD Serial
        $product = Product::create([
            'product_name' => 'Sold Product',
            'product_code' => 'SP001',
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
            'serial_number_required' => 1,
        ]);

        $serial = ProductSerialNumber::create([
            'product_id' => $product->id,
            'location_id' => 1,
            'serial_number' => 'SN-SOLD-REUSE',
            'status' => ProductSerialNumber::STATUS_SOLD,
        ]);

        // 2. Create Purchase
        $purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-REUSE-001',
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

        $detail = PurchaseDetail::create([
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

        // 3. No Cache mocking for now

        // 4. Store Receiving
        $response = $this->post(route('purchases.storeReceive', $purchase->id), [
            'received' => [$detail->id => 1],
            'serial_numbers' => [$detail->id => ['SN-SOLD-REUSE']],
            'location_id' => 1,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $receivedNote = ReceivedNote::where('po_id', $purchase->id)->first();
        $this->assertNotNull($receivedNote);
        $this->assertEquals(ReceivedNote::STATUS_PENDING, $receivedNote->status);

        // 5. Approve Receiving
        $response = $this->post(route('receivings.approve', $receivedNote->id));
        
        $response->assertSessionHasNoErrors();

        // 5. Assertions
        $serial->refresh();
        $this->assertEquals(ProductSerialNumber::STATUS_ACTIVE, $serial->status);
        $this->assertEquals(1, $product->fresh()->product_quantity);
    }
}
