<?php

namespace Modules\PurchasesReturn\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
use Modules\PurchasesReturn\Entities\SupplierCredit;
use Modules\PurchasesReturn\Entities\PurchasePaymentCreditApplication;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PurchaseReturnSettlementPhase2Test extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $supplier;
    protected $location;
    protected $product;
    protected $purchaseReturn;
    protected $setting;

    protected function setUp(): void
    {
        parent::setUp();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        \Spatie\Permission\Models\Permission::findOrCreate('purchaseReturnSettlements.approve');

        $this->user = User::factory()->create(['is_active' => 1]);
        $this->user->givePermissionTo('purchaseReturnSettlements.approve');
        $this->actingAs($this->user);

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

        $this->supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@example.com',
            'supplier_phone' => '12345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Test Address',
            'setting_id' => $this->setting->id,
        ]);

        $this->location = Location::create([
            'name' => 'Main Warehouse',
            'setting_id' => $this->setting->id,
        ]);

        $category = \Modules\Product\Entities\Category::create([
            'category_code' => 'TEST_CAT', 
            'category_name' => 'Test Category',
            'created_by' => $this->user->id,
            'setting_id' => $this->setting->id,
        ]);

        $this->product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TP001',
            'product_unit' => 'pcs',
            'product_price' => 1000,
            'product_cost' => 800,
            'category_id' => $category->id,
            'setting_id' => $this->setting->id,
        ]);

        $this->purchaseReturn = PurchaseReturn::create([
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'payment_method' => 'Cash',
            'reference' => 'PR-TEST',
            'status' => 'Pending',
            'approval_status' => 'approved',
            'date' => now(),
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'payment_status' => 'Unpaid',
        ]);

        PurchaseReturnDetail::create([
            'purchase_return_id' => $this->purchaseReturn->id,
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
    }

    /** @test */
    public function test_modify_purchase_approval_on_paid_purchase_deletes_payments()
    {
        // 1. Create a Paid Purchase with one payment
        $purchase = Purchase::create([
            'supplier_id' => $this->supplier->id,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'payment_status' => 'Paid',
            'status' => Purchase::STATUS_RECEIVED,
            'setting_id' => $this->setting->id,
            'date' => now(),
            'due_date' => now(),
            'reference' => 'PO-PAID',
            'payment_method' => 'Cash',
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $payment = PurchasePayment::create([
            'purchase_id' => $purchase->id, 
            'amount' => 1000, 
            'date' => now(),
            'payment_method' => 'Cash',
            'reference' => 'PAY-PAID',
        ]);

        // 1.1 Create Received Note to satisfy ensurePurchaseDetailsHaveQuantity
        $receivedNote = \Modules\Purchase\Entities\ReceivedNote::create([
            'po_id' => $purchase->id,
            'external_delivery_number' => 'DEL-PAID',
            'date' => now(),
            'status' => \Modules\Purchase\Entities\ReceivedNote::STATUS_APPROVED,
            'location_id' => $this->location->id,
        ]);

        \Modules\Purchase\Entities\ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'product_id' => $this->product->id,
            'po_detail_id' => $purchase->purchaseDetails->first()->id,
            'quantity_received' => 1,
        ]);

        // 2. Create MODIFY_PURCHASE settlement line
        $itemSettlement = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $this->purchaseReturn->id,
            'purchase_return_detail_id' => $this->purchaseReturn->purchaseReturnDetails->first()->id,
            'method' => 'MODIFY_PURCHASE',
            'target_purchase_id' => $purchase->id,
            'nominal' => 500, // Return 0.5 unit for example
            'status' => 'SUBMITTED',
        ]);

        // 3. Approve
        $response = $this->post(route('purchase-return-settlements.item.approve', $itemSettlement->id));
        $response->assertSessionHas('success');

        // 4. Verify
        $purchase->refresh();
        $this->assertEquals(0, $purchase->paid_amount);
        $this->assertEquals('UNPAID', $purchase->payment_status);
        $this->assertEquals(0, PurchasePayment::where('purchase_id', $purchase->id)->active()->count());
        $this->assertEquals(1, PurchasePayment::where('purchase_id', $purchase->id)->invalidated()->count());
    }

    /** @test */
    public function test_modify_purchase_allocation_can_store_optional_attachment_on_target_payment()
    {
        $sourcePurchase = Purchase::create([
            'supplier_id' => $this->supplier->id,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'payment_status' => 'Paid',
            'status' => Purchase::STATUS_RECEIVED,
            'setting_id' => $this->setting->id,
            'date' => now(),
            'due_date' => now(),
            'reference' => 'PO-SOURCE-ATTACH',
            'payment_method' => 'Cash',
        ]);

        $sourceDetail = PurchaseDetail::create([
            'purchase_id' => $sourcePurchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        PurchasePayment::create([
            'purchase_id' => $sourcePurchase->id,
            'amount' => 1000,
            'date' => now(),
            'payment_method' => 'Cash',
            'reference' => 'PAY-SOURCE-ATTACH',
        ]);

        $receivedNote = \Modules\Purchase\Entities\ReceivedNote::create([
            'po_id' => $sourcePurchase->id,
            'external_delivery_number' => 'DEL-ATTACH',
            'date' => now(),
            'status' => \Modules\Purchase\Entities\ReceivedNote::STATUS_APPROVED,
            'location_id' => $this->location->id,
        ]);

        \Modules\Purchase\Entities\ReceivedNoteDetail::create([
            'received_note_id' => $receivedNote->id,
            'product_id' => $this->product->id,
            'po_detail_id' => $sourceDetail->id,
            'quantity_received' => 1,
        ]);

        $targetPurchase = Purchase::create([
            'supplier_id' => $this->supplier->id,
            'total_amount' => 5000,
            'paid_amount' => 0,
            'due_amount' => 5000,
            'payment_status' => 'UNPAID',
            'status' => Purchase::STATUS_RECEIVED,
            'setting_id' => $this->setting->id,
            'date' => now(),
            'due_date' => now(),
            'reference' => 'PO-TARGET-ATTACH',
            'payment_method' => 'Cash',
        ]);

        PurchaseDetail::create([
            'purchase_id' => $targetPurchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 5,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 5000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $itemSettlement = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $this->purchaseReturn->id,
            'purchase_return_detail_id' => $this->purchaseReturn->purchaseReturnDetails->first()->id,
            'method' => 'MODIFY_PURCHASE',
            'target_purchase_id' => $sourcePurchase->id,
            'nominal' => 1000,
            'status' => 'SUBMITTED',
        ]);

        $file = UploadedFile::fake()->image('allocation-attachment.jpg');
        $response = $this->post(route('purchase-return-settlements.item.approve', $itemSettlement->id), [
            'allocation_purchase_id' => $targetPurchase->id,
            'attachments' => [$file],
        ]);

        $response->assertSessionHas('success');

        $targetPayment = PurchasePayment::where('purchase_id', $targetPurchase->id)->first();
        $this->assertNotNull($targetPayment);
        $this->assertEquals(1, $targetPayment->getMedia('attachments')->count());
    }

    /** @test */
    public function test_credit_approval_creates_purchase_payment_and_linkage()
    {
        Storage::fake('public');

        // 1. Target Purchase
        $purchase = Purchase::create([
            'supplier_id' => $this->supplier->id,
            'total_amount' => 5000,
            'paid_amount' => 0,
            'due_amount' => 5000,
            'payment_status' => 'UNPAID',
            'status' => Purchase::STATUS_RECEIVED,
            'setting_id' => $this->setting->id,
            'date' => now(),
            'due_date' => now(),
            'reference' => 'PO-TARGET',
            'payment_method' => 'CASH',
        ]);

        PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 5,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 5000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // 2. CREDIT settlement line
        $itemSettlement = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $this->purchaseReturn->id,
            'purchase_return_detail_id' => $this->purchaseReturn->purchaseReturnDetails->first()->id,
            'method' => 'CREDIT',
            'target_purchase_id' => $purchase->id,
            'nominal' => 1000,
            'status' => 'SUBMITTED',
        ]);

        // 3. Approve with note and attachment
        $file = UploadedFile::fake()->image('receipt.jpg');
        $response = $this->post(route('purchase-return-settlements.item.approve', $itemSettlement->id), [
            'approval_note' => 'TEST APPROVAL NOTE',
            'attachments' => [$file],
        ]);

        $response->assertSessionHas('success');

        // 4. Verify PurchasePayment
        $payment = PurchasePayment::first();
        $this->assertNotNull($payment);
        $this->assertEquals(1000, $payment->amount);
        $this->assertEquals('TEST APPROVAL NOTE', $payment->note);
        $this->assertEquals(1, $payment->getMedia('attachments')->count());

        // 5. Verify Purchase updates
        $purchase->refresh();
        $this->assertEquals(1000, $purchase->paid_amount);
        $this->assertEquals('PARTIAL', $purchase->payment_status);

        // 6. Verify SupplierCredit and Application Linkage
        $credit = SupplierCredit::where('purchase_return_id', $this->purchaseReturn->id)->first();
        $this->assertNotNull($credit);
        $this->assertEquals(1000, $credit->amount);
        $this->assertEquals(0, $credit->remaining_amount);
        $this->assertEquals('CLOSED', $credit->status);

        $application = PurchasePaymentCreditApplication::first();
        $this->assertNotNull($application);
        $this->assertEquals($payment->id, $application->purchase_payment_id);
        $this->assertEquals($credit->id, $application->supplier_credit_id);
        $this->assertEquals(1000, $application->amount);
    }

    /** @test */
    public function test_credit_approval_rejects_invalid_file_types()
    {
        $itemSettlement = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $this->purchaseReturn->id,
            'purchase_return_detail_id' => $this->purchaseReturn->purchaseReturnDetails->first()->id,
            'method' => 'CREDIT',
            'nominal' => 1000,
            'status' => 'SUBMITTED',
        ]);

        $file = UploadedFile::fake()->create('document.exe', 100);
        $response = $this->post(route('purchase-return-settlements.item.approve', $itemSettlement->id), [
            'attachments' => [$file],
        ]);

        $response->assertSessionHasErrors(['attachments.0']);
    }
}
