<?php

namespace Modules\PurchasesReturn\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Location;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Illuminate\Http\UploadedFile;

class PurchaseReturnLifecycleWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $location;
    protected $supplier;
    protected $product;
    protected $serialProduct;
    protected $dummyPurchase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Disable foreign key constraints for easy setup
        DB::statement('PRAGMA foreign_keys = OFF');
        
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        
        $permissions = [
            'purchaseReturns.create',
            'purchaseReturns.approval',
            'purchaseReturns.dispatchRequest',
            'purchaseReturns.dispatchApproval',
            'purchaseReturnSettlements.submit',
            'purchaseReturnSettlements.approve',
            'purchaseReturnSettlements.receive',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->user = User::factory()->create(['is_active' => 1]);
        $this->user->givePermissionTo($permissions);
        $this->actingAs($this->user);

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

        $this->location = Location::create([
            'name' => 'Main Warehouse',
            'setting_id' => $this->setting->id,
        ]);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@example.com',
            'supplier_phone' => '12345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Test Address',
            'setting_id' => $this->setting->id,
        ]);

        $this->dummyPurchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-001',
            'supplier_id' => $this->supplier->id,
            'status' => 'APPROVED',
            'payment_status' => 'UNPAID',
            'payment_method' => 'Cash',
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'setting_id' => $this->setting->id,
        ]);

        // Add dummy details and received note to satisfy MODIFY_PURCHASE requirements
        $pd1 = PurchaseDetail::create([
            'purchase_id' => $this->dummyPurchase->id,
            'product_id' => 1, // Will be updated by real product id after product creation
            'quantity' => 10,
            'unit_price' => 1000,
            'price' => 1000,
            'sub_total' => 10000,
            'product_name' => 'Temp',
            'product_code' => 'Temp',
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $pd2 = PurchaseDetail::create([
            'purchase_id' => $this->dummyPurchase->id,
            'product_id' => 2, // Will be updated by real product id after product creation
            'quantity' => 10,
            'unit_price' => 2000,
            'price' => 2000,
            'sub_total' => 20000,
            'product_name' => 'Temp',
            'product_code' => 'Temp',
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $rn = ReceivedNote::create([
            'po_id' => $this->dummyPurchase->id,
            'date' => now(),
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_APPROVED,
        ]);

        ReceivedNoteDetail::create([
            'received_note_id' => $rn->id,
            'po_detail_id' => $pd1->id,
            'quantity_received' => 10,
        ]);

        ReceivedNoteDetail::create([
            'received_note_id' => $rn->id,
            'po_detail_id' => $pd2->id,
            'quantity_received' => 10,
        ]);

        $category = \Modules\Product\Entities\Category::create([
            'category_code' => 'CAT001',
            'category_name' => 'Category 1',
            'created_by' => $this->user->id,
            'setting_id' => $this->setting->id,
        ]);

        $this->product = Product::create([
            'product_name' => 'Non-Serial Product',
            'product_code' => 'P001',
            'product_unit' => 'pcs',
            'product_price' => 1000,
            'product_cost' => 800,
            'category_id' => $category->id,
            'setting_id' => $this->setting->id,
            'product_quantity' => 10,
            'serial_number_required' => false,
        ]);

        $this->serialProduct = Product::create([
            'product_name' => 'Serial Product',
            'product_code' => 'SP001',
            'product_unit' => 'pcs',
            'product_price' => 2000,
            'product_cost' => 1500,
            'category_id' => $category->id,
            'setting_id' => $this->setting->id,
            'product_quantity' => 5,
            'serial_number_required' => true,
        ]);

        // Update dummy purchase details with real product info
        $pd1->update([
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
        ]);
        $pd2->update([
            'product_id' => $this->serialProduct->id,
            'product_name' => $this->serialProduct->product_name,
            'product_code' => $this->serialProduct->product_code,
        ]);

        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_tax' => 10,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        ProductStock::create([
            'product_id' => $this->serialProduct->id,
            'location_id' => $this->location->id,
            'quantity' => 5,
            'quantity_tax' => 5,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);
    }

    /** @test */
    public function test_full_lifecycle_non_serial_modify_purchase()
    {
        // 1. Create PR
        $pr = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PRRN-LIFECYCLE-001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'draft',
        ]);

        $detail = PurchaseReturnDetail::create([
            'purchase_return_id' => $pr->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
            'po_id' => $this->dummyPurchase->id,
        ]);

        $this->assertEquals(PurchaseReturn::STATUS_DRAFT, $pr->unified_status);

        // 2. Submit for approval
        $pr->update(['approval_status' => 'pending']);
        $this->assertEquals(PurchaseReturn::STATUS_PENDING_APPROVAL, $pr->unified_status);

        // 3. Approve PR
        $this->post(route('purchase-returns.approve', $pr->id));
        $pr->refresh();
        $this->assertEquals(PurchaseReturn::STATUS_AWAITING_DISPATCH, $pr->unified_status);

        // 4. Request dispatch
        $this->post(route('purchase-returns.dispatch-request', $pr->id), [
            'return_dispatch_note' => 'Dispatch it',
            'return_shipping_amount' => '0',
            'return_awb_attachments' => [UploadedFile::fake()->image('awb.jpg')]
        ])->assertSessionHasNoErrors();
        
        $pr->refresh();
        $this->assertEquals(PurchaseReturn::STATUS_DISPATCH_PENDING_APPROVAL, $pr->unified_status);

        // 5. Approve dispatch
        $this->post(route('purchase-returns.dispatch-approve', $pr->id));
        $pr->refresh();
        $this->assertEquals(PurchaseReturn::STATUS_IN_RETURN, $pr->unified_status);

        // 6. Manual Settlement creation
        $item = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'MODIFY_PURCHASE',
            'nominal' => 1000,
            'status' => PurchaseReturnItemSettlement::STATUS_SUBMITTED,
            'target_purchase_id' => $this->dummyPurchase->id,
        ]);
        
        $pr->refresh();
        $this->assertEquals(PurchaseReturn::STATUS_SETTLEMENT_CONFIRMATION_PENDING, $pr->unified_status);

        // 7. Approve settlement
        $this->post(route('purchase-return-settlements.item.approve', $item->id));
        $pr->refresh();
        $this->assertEquals(PurchaseReturn::STATUS_COMPLETED, $pr->unified_status);
    }

    /** @test */
    public function test_partial_settlement_workflow()
    {
        // Setup PR with 2 lines
        $pr = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PRRN-PARTIAL-001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'approved',
            'return_dispatch_status' => 'dispatched',
        ]);

        $detail1 = PurchaseReturnDetail::create([
            'purchase_return_id' => $pr->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => 'P1',
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
        ]);

        $detail2 = PurchaseReturnDetail::create([
            'purchase_return_id' => $pr->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => 'P2',
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
        ]);

        $this->assertEquals(PurchaseReturn::STATUS_IN_RETURN, $pr->refresh()->unified_status);

        // Settlement for line 1
        $item1 = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail1->id,
            'method' => 'CASH',
            'nominal' => 1000,
            'status' => PurchaseReturnItemSettlement::STATUS_SUBMITTED,
        ]);
        
        $this->post(route('purchase-return-settlements.item.approve', $item1->id));
        
        $pr->refresh();
        $this->assertEquals(PurchaseReturn::STATUS_PARTIAL_SETTLEMENT, $pr->unified_status);

        // Settlement for line 2
        $item2 = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail2->id,
            'method' => 'CASH',
            'nominal' => 1000,
            'status' => PurchaseReturnItemSettlement::STATUS_SUBMITTED,
        ]);
        
        $this->post(route('purchase-return-settlements.item.approve', $item2->id));
        
        $pr->refresh();
        $this->assertEquals(PurchaseReturn::STATUS_COMPLETED, $pr->unified_status);
    }

    /** @test */
    public function test_full_lifecycle_serial_product_repair()
    {
        $sn = ProductSerialNumber::create([
            'product_id' => $this->serialProduct->id,
            'serial_number' => 'SN-REP-001',
            'status' => 'ACTIVE',
            'location_id' => $this->location->id,
        ]);

        $pr = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PRRN-SERIAL-REP-001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'approved',
            'return_dispatch_status' => 'pending_approval',
        ]);

        $detail = PurchaseReturnDetail::create([
            'purchase_return_id' => $pr->id,
            'product_id' => $this->serialProduct->id,
            'product_name' => $this->serialProduct->product_name,
            'product_code' => $this->serialProduct->product_code,
            'quantity' => 1,
            'price' => 2000,
            'unit_price' => 2000,
            'sub_total' => 2000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
            'serial_number_ids' => [$sn->id],
        ]);

        // Dispatch approval
        $this->post(route('purchase-returns.dispatch-approve', $pr->id), [
            'serials' => [$detail->id => [$sn->id]]
        ]);
        
        $sn->refresh();
        $this->assertEquals('RETURN_IN_PROCESS', $sn->status);
        $this->assertTrue((bool)$sn->is_in_return_process);

        // Settlement creation
        $item = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'PRODUCT_REPAIR',
            'nominal' => 2000,
            'product_serial_number_id' => $sn->id,
            'status' => PurchaseReturnItemSettlement::STATUS_SUBMITTED,
        ]);
        
        $this->post(route('purchase-return-settlements.item.approve', $item->id));
        
        $pr->refresh();
        $this->assertEquals(PurchaseReturn::STATUS_WAITING_REPLACEMENT_GOODS, $pr->unified_status);

        // Receive repair
        $newSnText = 'SN-NEW-001';
        $this->post(route('purchase-return-settlements.item.receive', $item->id), [
            'location_id' => $this->location->id,
            'received_quantity' => 1,
            'replacement_serial_number' => $newSnText,
        ]);

        $pr->refresh();
        $this->assertEquals(PurchaseReturn::STATUS_COMPLETED, $pr->unified_status);
        
        $sn->refresh();
        $this->assertEquals('RETURNED', $sn->status);
        $this->assertFalse((bool)$sn->is_in_return_process);

        $newSn = ProductSerialNumber::where('serial_number', $newSnText)->first();
        $this->assertNotNull($newSn);
        $this->assertEquals('ACTIVE', $newSn->status);
    }

    /** @test */
    public function test_rejected_item_keeps_document_in_return()
    {
        $pr = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PRRN-REJECTED-ITEM-001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'approved',
            'return_dispatch_status' => 'dispatched',
        ]);

        $detail = PurchaseReturnDetail::create([
            'purchase_return_id' => $pr->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
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
            'status' => PurchaseReturnItemSettlement::STATUS_SUBMITTED,
            'target_purchase_id' => $this->dummyPurchase->id,
        ]);
        
        $this->post(route('purchase-return-settlements.item.reject', $item->id), [
            'rejection_reason' => 'Invalid settlement'
        ]);

        $pr->refresh();
        $this->assertEquals(PurchaseReturn::STATUS_IN_RETURN, $pr->unified_status);
        $this->assertEquals('REJECTED', $item->refresh()->status);
    }

    /** @test */
    public function test_mixed_method_partial_completion()
    {
        $pr = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PRRN-MIXED-001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'total_amount' => 3000,
            'paid_amount' => 0,
            'due_amount' => 3000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'approved',
            'return_dispatch_status' => 'dispatched',
        ]);

        $detail1 = PurchaseReturnDetail::create([
            'purchase_return_id' => $pr->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => 'P1',
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
            'po_id' => $this->dummyPurchase->id,
        ]);

        $detail2 = PurchaseReturnDetail::create([
            'purchase_return_id' => $pr->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => 'P2',
            'quantity' => 1,
            'price' => 2000,
            'unit_price' => 2000,
            'sub_total' => 2000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
        ]);

        $item1 = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail1->id,
            'method' => 'MODIFY_PURCHASE',
            'nominal' => 1000,
            'status' => PurchaseReturnItemSettlement::STATUS_SUBMITTED,
            'target_purchase_id' => $this->dummyPurchase->id,
        ]);

        $item2 = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail2->id,
            'method' => 'PRODUCT_REPAIR',
            'nominal' => 2000,
            'status' => PurchaseReturnItemSettlement::STATUS_SUBMITTED,
        ]);
        
        $this->post(route('purchase-return-settlements.item.approve', $item1->id))->assertSessionHasNoErrors();
        $pr->refresh();
        $this->assertEquals(PurchaseReturn::STATUS_PARTIAL_SETTLEMENT, $pr->unified_status);

        $this->post(route('purchase-return-settlements.item.approve', $item2->id))->assertSessionHasNoErrors();
        $pr->refresh();
        $this->assertEquals(PurchaseReturn::STATUS_PARTIAL_SETTLEMENT, $pr->unified_status);

        $this->post(route('purchase-return-settlements.item.receive', $item2->id), [
            'location_id' => $this->location->id,
            'received_quantity' => 1,
        ])->assertSessionHasNoErrors();
        
        $pr->refresh();
        $this->assertEquals(PurchaseReturn::STATUS_COMPLETED, $pr->unified_status);
    }
}
