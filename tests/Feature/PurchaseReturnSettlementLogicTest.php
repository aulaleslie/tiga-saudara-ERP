<?php

namespace Modules\PurchasesReturn\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
use Modules\Product\Entities\Product;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\PurchasePayment;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Gate;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;

class PurchaseReturnSettlementLogicTest extends TestCase
{
    use RefreshDatabase;

    protected $supplier;
    protected $product;
    protected $location;

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
             'company_email' => 'test@company.com',
             'company_phone' => '1234567890',
             'company_address' => 'Test Address',
             'default_currency_id' => 1,
             'default_currency_position' => 'prefix',
             'notification_email' => 'notification@test.com',
             'footer_text' => 'Test Footer',
        ]);
        
        $this->supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@supplier.com',
            'supplier_phone' => '1234567890',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
            'setting_id' => 1,
        ]);

        $this->product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'product_quantity' => 100,
            'product_cost' => 10000,
            'product_price' => 12000,
            'product_unit' => 'pcs',
            'product_stock_alert' => 10,
            'setting_id' => 1,
        ]);
        
        $this->location = Location::create([
             'name' => 'Test Location',
             'setting_id' => 1,
        ]);
        
        // Mock session setting_id for controller usage
        session(['setting_id' => 1]);
        
        Gate::define('purchaseReturnSettlements.approve', fn() => true);
    }

    /** @test */
    public function it_reduces_debt_for_partial_purchase_when_return_amount_is_less_than_due_amount()
    {
        // 1. Create Partial Purchase (Debt = 50,000)
        DB::statement("INSERT INTO purchases (date, due_date, reference, supplier_id, payment_method, tax_percentage, discount_percentage, shipping_amount, paid_amount, total_amount, due_amount, status, payment_status, setting_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            now(), now(),
            'PUR-001',
            $this->supplier->id,
            'Cash',
            0, 0, 0,
            50000, 100000, 50000,
            'Received', 'Partial',
            1,
            now(), now()
        ]);
        $purchaseId = DB::getPdo()->lastInsertId();
        $purchase = Purchase::find($purchaseId);

        $purchaseDetail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 10,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 100000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $receivedNote = ReceivedNote::create([
             'date' => now(),
             'reference' => 'GRN-001',
             'supplier_id' => $this->supplier->id,
             'setting_id' => 1,
             'status' => ReceivedNote::STATUS_APPROVED,
             'po_id' => $purchase->id,
        ]);
        
        ReceivedNoteDetail::create([
             'received_note_id' => $receivedNote->id,
             'po_detail_id' => $purchaseDetail->id,
             'product_id' => $this->product->id,
             'quantity_received' => 10,
             'product_code' => $this->product->product_code,
             'product_name' => $this->product->product_name,
             'unit_price' => 10000,
             'sub_total' => 100000,
             'product_tax_amount' => 0,
             'product_discount_amount' => 0,
             'product_discount_type' => 'fixed',
        ]);

        // 2. Create Return (Return 2 items = 20,000)
        $return = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PR-001',
            'setting_id' => 1,
            'location_id' => $this->location->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'payment_method' => 'Cash',
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'paid_amount' => 0,
            'total_amount' => 20000,
            'due_amount' => 20000,
            'status' => 'Pending',
            'approval_status' => 'Approved',
            'return_dispatched_at' => now(),
            'payment_status' => 'Unpaid',
        ]);

        $detail = PurchaseReturnDetail::create([
            'purchase_return_id' => $return->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 2,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 20000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        // 3. Create Settlement Item targeting the purchase
        $settlementItem = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $return->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'MODIFY_PURCHASE',
            'nominal' => 20000,
            'target_purchase_id' => $purchase->id,
            'status' => 'SUBMITTED',
        ]);

        // 4. Approve Settlement
        // Note: Logic allows debt reduction if Surplus <= 0
        $this->actingAs( \App\Models\User::factory()->create() );
        $response = $this->post(route('purchase-return-settlements.item.approve', $settlementItem->id));
        
        
        
        
        
        $response->assertSessionHas('success');

        // 5. Assertions
        $purchase->refresh();
        // New Total: 100,000 - 20,000 = 80,000
        // Paid: 50,000 (Unchanged)
        // Due: 80,000 - 50,000 = 30,000
        $this->assertEquals(80000, $purchase->total_amount);
        $this->assertEquals(30000, $purchase->due_amount);
        $this->assertEquals(50000, $purchase->paid_amount);
        
        // Assert no extra payments created
        $this->assertEquals(0, PurchasePayment::where('purchase_id', '!=', $purchase->id)->count());
    }

    /** @test */
    public function it_creates_payment_on_target_purchase_when_source_purchase_is_paid_resulting_in_surplus()
    {
        // 1. Create Paid Purchase (Source)
        DB::statement("INSERT INTO purchases (date, due_date, reference, supplier_id, payment_method, tax_percentage, discount_percentage, shipping_amount, paid_amount, total_amount, due_amount, status, payment_status, setting_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            now(), now(),
            'PUR-SOURCE',
            $this->supplier->id,
            'Cash',
            0, 0, 0,
            100000, 100000, 0,
            'Received', 'Paid',
            1,
            now(), now()
        ]);
        $sourceId = DB::getPdo()->lastInsertId();
        $sourcePurchase = Purchase::find($sourceId);
        
         $sourceDetail = PurchaseDetail::create([
            'purchase_id' => $sourcePurchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 10,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 100000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $receivedNote2 = ReceivedNote::create([
             'date' => now(),
             'reference' => 'GRN-002',
             'supplier_id' => $this->supplier->id,
             'setting_id' => 1,
             'status' => ReceivedNote::STATUS_APPROVED,
             'po_id' => $sourcePurchase->id,
        ]);
        
        ReceivedNoteDetail::create([
             'received_note_id' => $receivedNote2->id,
             'po_detail_id' => $sourceDetail->id,
             'product_id' => $this->product->id,
             'quantity_received' => 10,
             'product_code' => $this->product->product_code,
             'product_name' => $this->product->product_name,
             'unit_price' => 10000,
             'sub_total' => 100000,
             'product_tax_amount' => 0,
             'product_discount_amount' => 0,
             'product_discount_type' => 'fixed',
        ]);

        // 2. Create Unpaid Purchase (Target)
        DB::statement("INSERT INTO purchases (date, due_date, reference, supplier_id, payment_method, tax_percentage, discount_percentage, shipping_amount, paid_amount, total_amount, due_amount, status, payment_status, setting_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            now(), now(),
            'PUR-TARGET',
            $this->supplier->id,
            'Cash', // Same Supplier
            0, 0, 0,
            0, 200000, 200000,
            'Received', 'Unpaid',
            1,
            now(), now()
        ]);
        $targetId = DB::getPdo()->lastInsertId();
        $targetPurchase = Purchase::find($targetId);

        // 3. Create Return (Return 2 items = 20,000)
        $return = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PR-002',
            'setting_id' => 1,
            'location_id' => $this->location->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'payment_method' => 'Cash',
            'paid_amount' => 0,
            'total_amount' => 20000,
            'due_amount' => 20000,
            'status' => 'Pending',
            'approval_status' => 'Approved',
            'return_dispatched_at' => now(),
            'payment_status' => 'Unpaid',
        ]);
        
        $detail = PurchaseReturnDetail::create([
            'purchase_return_id' => $return->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 2,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 20000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        // 4. Create Settlement Item
        $settlementItem = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $return->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'MODIFY_PURCHASE',
            'nominal' => 20000,
            'target_purchase_id' => $sourcePurchase->id, // Source purchase to modify
            'status' => 'SUBMITTED',
        ]);

        // 5. Approve with Allocation Target
        // Create an actual payment for source purchase to verify deletion
        PurchasePayment::create([
            'purchase_id' => $sourcePurchase->id,
            'amount' => 10000000, // 100,000 * 100
            'date' => now(),
            'reference' => 'PAY-OLD',
            'payment_method' => 'Cash',
        ]);

        $this->actingAs( \App\Models\User::factory()->create() );
        $response = $this->post(route('purchase-return-settlements.item.approve', $settlementItem->id), [
            'allocation_purchase_id' => $targetPurchase->id, 
        ]);
        
        $response->assertSessionHas('success');

        // 6. Assertions
        $sourcePurchase->refresh();
        // Source modified: 100,000 - 20,000 = 80,000 Total.
        // Paid: 80,000 (matched new total since all payments were deleted and moved)
        $this->assertEquals(80000, $sourcePurchase->total_amount);
        $this->assertEquals(80000, $sourcePurchase->paid_amount);

        // Assert source payment is deleted
        $this->assertEquals(0, PurchasePayment::where('purchase_id', $sourcePurchase->id)->count());

        $targetPurchase->refresh();
        // Target: Due should decrase by 20,000 (via new payment)
        $this->assertEquals(180000, $targetPurchase->due_amount);
        $this->assertEquals(20000, $targetPurchase->paid_amount);
        
        // Assert Payment Created on Target
        $this->assertDatabaseHas('purchase_payments', [
            'purchase_id' => $targetPurchase->id,
            'amount' => 2000000, // PurchasePayment model stores cents (x100)
            'payment_method' => 'SETTLEMENT RETUR', // BaseModel uppercases text
        ]);
    }

    /** @test */
    public function it_removes_all_payments_when_source_is_paid_without_allocation_target()
    {
        // 1. Create Paid Purchase (Source)
        DB::statement("INSERT INTO purchases (date, due_date, reference, supplier_id, payment_method, tax_percentage, discount_percentage, shipping_amount, paid_amount, total_amount, due_amount, status, payment_status, setting_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            now(), now(),
            'PUR-PAID',
            $this->supplier->id,
            'Cash',
            0, 0, 0,
            100000, 100000, 0,
            'Received', 'Paid',
            1,
            now(), now()
        ]);
        $purchaseId = DB::getPdo()->lastInsertId();
        $purchase = Purchase::find($purchaseId);
        
        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 10,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 100000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $receivedNote = ReceivedNote::create([
             'date' => now(),
             'reference' => 'GRN-PAID',
             'supplier_id' => $this->supplier->id,
             'setting_id' => 1,
             'status' => ReceivedNote::STATUS_APPROVED,
             'po_id' => $purchase->id,
        ]);
        
        ReceivedNoteDetail::create([
             'received_note_id' => $receivedNote->id,
             'po_detail_id' => $detail->id,
             'product_id' => $this->product->id,
             'quantity_received' => 10,
             'product_code' => $this->product->product_code,
             'product_name' => $this->product->product_name,
             'unit_price' => 10000,
             'sub_total' => 100000,
             'product_tax_amount' => 0,
             'product_discount_amount' => 0,
             'product_discount_type' => 'fixed',
        ]);

        // Create Payment
        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 10000000, // 100,000 * 100
            'date' => now(),
            'reference' => 'PAY-PAID',
            'payment_method' => 'Cash',
        ]);

        // 2. Create Return (Return 2 items = 20,000)
        $return = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PR-PAID',
            'setting_id' => 1,
            'location_id' => $this->location->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'payment_method' => 'Cash',
            'total_amount' => 20000,
            'paid_amount' => 0,
            'due_amount' => 20000,
            'status' => 'Pending',
            'approval_status' => 'Approved',
            'return_dispatched_at' => now(),
            'payment_status' => 'Unpaid',
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
        ]);
        
        $returnDetail = PurchaseReturnDetail::create([
            'purchase_return_id' => $return->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 2,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 20000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        // 3. Create Settlement Item
        $settlementItem = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $return->id,
            'purchase_return_detail_id' => $returnDetail->id,
            'method' => 'MODIFY_PURCHASE',
            'nominal' => 20000,
            'target_purchase_id' => $purchase->id,
            'status' => 'SUBMITTED',
        ]);

        // 4. Approve
        $this->actingAs( \App\Models\User::factory()->create() );
        $response = $this->post(route('purchase-return-settlements.item.approve', $settlementItem->id));
        
        $response->assertSessionHas('success');

        // 5. Assertions
        $purchase->refresh();
        // New Total: 80,000. Paid: 80,000 (matched new total since all payments were deleted). Due: 0. Status: PAID.
        $this->assertEquals(80000, $purchase->total_amount);
        $this->assertEquals(80000, $purchase->paid_amount);
        $this->assertEquals(0, $purchase->due_amount);
        $this->assertEquals('PAID', $purchase->payment_status);
        $this->assertEquals(0, PurchasePayment::where('purchase_id', $purchase->id)->count());
    }

    /** @test */
    public function it_removes_all_payments_when_partial_remainder_is_less_than_return()
    {
        // 1. Create Partial Purchase (Total 100k, Paid 80k, Due 20k)
        DB::statement("INSERT INTO purchases (date, due_date, reference, supplier_id, payment_method, tax_percentage, discount_percentage, shipping_amount, paid_amount, total_amount, due_amount, status, payment_status, setting_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            now(), now(),
            'PUR-PARTIAL-LESS',
            $this->supplier->id,
            'Cash',
            0, 0, 0,
            80000, 100000, 20000,
            'Received', 'Partial',
            1,
            now(), now()
        ]);
        $purchaseId = DB::getPdo()->lastInsertId();
        $purchase = Purchase::find($purchaseId);
        
        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 10,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 100000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $receivedNote = ReceivedNote::create([
             'date' => now(),
             'reference' => 'GRN-PARTIAL-LESS',
             'supplier_id' => $this->supplier->id,
             'setting_id' => 1,
             'status' => ReceivedNote::STATUS_APPROVED,
             'po_id' => $purchase->id,
        ]);
        
        ReceivedNoteDetail::create([
             'received_note_id' => $receivedNote->id,
             'po_detail_id' => $detail->id,
             'product_id' => $this->product->id,
             'quantity_received' => 10,
             'product_code' => $this->product->product_code,
             'product_name' => $this->product->product_name,
             'unit_price' => 10000,
             'sub_total' => 100000,
             'product_tax_amount' => 0,
             'product_discount_amount' => 0,
             'product_discount_type' => 'fixed',
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 8000000, // 80,000 * 100
            'date' => now(),
            'reference' => 'PAY-PARTIAL-LESS',
            'payment_method' => 'Cash',
        ]);

        // 2. Create Return 30k (Greater than Due 20k)
        $return = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PR-PARTIAL-LESS',
            'setting_id' => 1,
            'location_id' => $this->location->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'payment_method' => 'Cash',
            'total_amount' => 30000,
            'paid_amount' => 0,
            'due_amount' => 30000,
            'status' => 'Pending',
            'approval_status' => 'Approved',
            'return_dispatched_at' => now(),
            'payment_status' => 'Unpaid',
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
        ]);
        
        $returnDetail = PurchaseReturnDetail::create([
            'purchase_return_id' => $return->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 3,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 30000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        // 3. Create Settlement Item
        $settlementItem = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $return->id,
            'purchase_return_detail_id' => $returnDetail->id,
            'method' => 'MODIFY_PURCHASE',
            'nominal' => 30000,
            'target_purchase_id' => $purchase->id,
            'status' => 'SUBMITTED',
        ]);

        // 4. Approve
        $this->actingAs( \App\Models\User::factory()->create() );
        $response = $this->post(route('purchase-return-settlements.item.approve', $settlementItem->id));
        
        $response->assertSessionHas('success');

        // 5. Assertions
        $purchase->refresh();
        // New Total: 70,000. Paid: 70,000 (matched new total since all payments were deleted). Due: 0. Status: PAID.
        $this->assertEquals(70000, $purchase->total_amount);
        $this->assertEquals(70000, $purchase->paid_amount);
        $this->assertEquals(0, $purchase->due_amount);
        $this->assertEquals('PAID', $purchase->payment_status);
        $this->assertEquals(0, PurchasePayment::where('purchase_id', $purchase->id)->count());
    }

    /** @test */
    public function it_keeps_payments_when_partial_remainder_is_greater_than_return()
    {
        // 1. Create Partial Purchase (Total 100k, Paid 50k, Due 50k)
        DB::statement("INSERT INTO purchases (date, due_date, reference, supplier_id, payment_method, tax_percentage, discount_percentage, shipping_amount, paid_amount, total_amount, due_amount, status, payment_status, setting_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
            now(), now(),
            'PUR-PARTIAL-MORE',
            $this->supplier->id,
            'Cash',
            0, 0, 0,
            50000, 100000, 50000,
            'Received', 'Partial',
            1,
            now(), now()
        ]);
        $purchaseId = DB::getPdo()->lastInsertId();
        $purchase = Purchase::find($purchaseId);
        
        $detail = PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 10,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 100000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $receivedNote = ReceivedNote::create([
             'date' => now(),
             'reference' => 'GRN-PARTIAL-MORE',
             'supplier_id' => $this->supplier->id,
             'setting_id' => 1,
             'status' => ReceivedNote::STATUS_APPROVED,
             'po_id' => $purchase->id,
        ]);
        
        ReceivedNoteDetail::create([
             'received_note_id' => $receivedNote->id,
             'po_detail_id' => $detail->id,
             'product_id' => $this->product->id,
             'quantity_received' => 10,
             'product_code' => $this->product->product_code,
             'product_name' => $this->product->product_name,
             'unit_price' => 10000,
             'sub_total' => 100000,
             'product_tax_amount' => 0,
             'product_discount_amount' => 0,
             'product_discount_type' => 'fixed',
        ]);

        PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 5000000, // 50,000 * 100
            'date' => now(),
            'reference' => 'PAY-PARTIAL-MORE',
            'payment_method' => 'Cash',
        ]);

        // 2. Create Return 20k (Less than Due 50k)
        $return = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PR-PARTIAL-MORE',
            'setting_id' => 1,
            'location_id' => $this->location->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'payment_method' => 'Cash',
            'total_amount' => 20000,
            'paid_amount' => 0,
            'due_amount' => 20000,
            'status' => 'Pending',
            'approval_status' => 'Approved',
            'return_dispatched_at' => now(),
            'payment_status' => 'Unpaid',
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
        ]);
        
        $returnDetail = PurchaseReturnDetail::create([
            'purchase_return_id' => $return->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 2,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 20000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        // 3. Create Settlement Item
        $settlementItem = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $return->id,
            'purchase_return_detail_id' => $returnDetail->id,
            'method' => 'MODIFY_PURCHASE',
            'nominal' => 20000,
            'target_purchase_id' => $purchase->id,
            'status' => 'SUBMITTED',
        ]);

        // 4. Approve
        $this->actingAs( \App\Models\User::factory()->create() );
        $response = $this->post(route('purchase-return-settlements.item.approve', $settlementItem->id));
        
        $response->assertSessionHas('success');

        // 5. Assertions
        $purchase->refresh();
        // New Total: 80,000. Paid: 50,000 (kept because return 20k <= due 50k). Due: 30,000. Status: Partial.
        $this->assertEquals(80000, $purchase->total_amount);
        $this->assertEquals(50000, $purchase->paid_amount);
        $this->assertEquals(30000, $purchase->due_amount);
        $this->assertEquals('PARTIAL', $purchase->payment_status);
        $this->assertEquals(1, PurchasePayment::where('purchase_id', $purchase->id)->count());
    }
}
