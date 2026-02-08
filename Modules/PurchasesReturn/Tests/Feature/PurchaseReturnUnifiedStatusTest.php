<?php

namespace Modules\PurchasesReturn\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;
use App\Models\User;

class PurchaseReturnUnifiedStatusTest extends TestCase
{
    use RefreshDatabase;

    protected $setting;
    protected $user;
    protected $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        
        \Modules\Currency\Entities\Currency::create([
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
             'company_phone' => '1234567890',
             'company_address' => 'Test Address',
             'default_currency_id' => 1,
             'default_currency_position' => 'prefix',
             'notification_email' => 'notification@test.com',
             'footer_text' => 'Test Footer',
        ]);

        $this->supplier = \Modules\People\Entities\Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@supplier.com',
            'supplier_phone' => '1234567890',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
            'setting_id' => 1,
        ]);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        session(['setting_id' => $this->setting->id]);

        $this->product = Product::create([
            'id' => 1,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'product_quantity' => 100,
            'product_cost' => 10000,
            'product_price' => 12000,
            'product_unit' => 'pcs',
            'product_stock_alert' => 10,
            'setting_id' => 1,
        ]);
    }

    protected function createPR(array $attributes = [])
    {
        return PurchaseReturn::create(array_merge([
            'setting_id' => $this->setting->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'date' => now(),
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
        ], $attributes));
    }

    /** @test */
    public function it_returns_draft_status_when_approval_status_is_draft_or_empty()
    {
        $pr1 = $this->createPR(['approval_status' => 'draft']);
        $pr2 = $this->createPR(['approval_status' => '']);

        $this->assertEquals(PurchaseReturn::STATUS_DRAFT, $pr1->unified_status);
        $this->assertEquals(PurchaseReturn::STATUS_DRAFT, $pr2->unified_status);
        $this->assertEquals('Draf', $pr1->unified_status_label);
    }

    /** @test */
    public function it_returns_pending_approval_status()
    {
        $pr = $this->createPR(['approval_status' => 'pending']);

        $this->assertEquals(PurchaseReturn::STATUS_PENDING_APPROVAL, $pr->unified_status);
        $this->assertEquals('Menunggu Persetujuan', $pr->unified_status_label);
    }

    /** @test */
    public function it_returns_rejected_status()
    {
        $pr = $this->createPR(['approval_status' => 'rejected']);

        $this->assertEquals(PurchaseReturn::STATUS_REJECTED, $pr->unified_status);
        $this->assertEquals('Ditolak', $pr->unified_status_label);
    }

    /** @test */
    public function it_returns_awaiting_dispatch_when_approved_but_no_dispatch()
    {
        $pr = $this->createPR([
            'approval_status' => 'approved',
            'return_dispatch_status' => null,
        ]);

        $this->assertEquals(PurchaseReturn::STATUS_AWAITING_DISPATCH, $pr->unified_status);
    }

    /** @test */
    public function it_returns_dispatch_pending_approval_status()
    {
        $pr = $this->createPR([
            'approval_status' => 'approved',
            'return_dispatch_status' => 'pending_approval',
        ]);

        $this->assertEquals(PurchaseReturn::STATUS_DISPATCH_PENDING_APPROVAL, $pr->unified_status);
    }

    /** @test */
    public function it_returns_in_return_when_dispatched_with_no_settlement()
    {
        $pr = $this->createPR([
            'approval_status' => 'approved',
            'return_dispatch_status' => 'dispatched',
        ]);

        $this->assertEquals(PurchaseReturn::STATUS_IN_RETURN, $pr->unified_status);
        $this->assertEquals('Sedang Dalam Retur, Menunggu Input Penyelesaian', $pr->unified_status_label);
    }

    /** @test */
    public function it_returns_waiting_settlement_confirmation_when_items_are_submitted()
    {
        $pr = $this->createPR([
            'approval_status' => 'approved',
            'return_dispatch_status' => 'dispatched',
        ]);

        $detail = \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::create([
            'purchase_return_id' => $pr->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'MODIFY_PURCHASE',
            'status' => 'SUBMITTED',
        ]);

        $this->assertEquals(PurchaseReturn::STATUS_SETTLEMENT_CONFIRMATION_PENDING, $pr->unified_status);
        $this->assertEquals('Menunggu Konfirmasi Penyelesaian', $pr->unified_status_label);
    }

    /** @test */
    public function it_returns_waiting_replacement_goods_when_approved_awaiting_receive_exists_without_final_items()
    {
        $pr = $this->createPR([
            'approval_status' => 'approved',
            'return_dispatch_status' => 'dispatched',
        ]);

        $detail = \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::create([
            'purchase_return_id' => $pr->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'PRODUCT_REPAIR',
            'status' => 'APPROVED_AWAITING_RECEIVE',
        ]);

        $this->assertEquals(PurchaseReturn::STATUS_WAITING_REPLACEMENT_GOODS, $pr->unified_status);
        $this->assertEquals('Menunggu Barang Pengganti', $pr->unified_status_label);
    }

    /** @test */
    public function it_returns_partial_settlement_when_some_items_final()
    {
        $pr = $this->createPR([
            'approval_status' => 'approved',
            'return_dispatch_status' => 'dispatched',
        ]);

        $detail = \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::create([
            'purchase_return_id' => $pr->id,
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

        // One final item (MODIFY_PURCHASE + APPROVED)
        PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'MODIFY_PURCHASE',
            'status' => 'APPROVED',
        ]);

        // One non-final item (PRODUCT_REPAIR + APPROVED_AWAITING_RECEIVE)
        PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'PRODUCT_REPAIR',
            'status' => 'APPROVED_AWAITING_RECEIVE',
        ]);

        $this->assertEquals(PurchaseReturn::STATUS_PARTIAL_SETTLEMENT, $pr->unified_status);
        $this->assertEquals('Penyelesaian Disetujui Sebagian', $pr->unified_status_label);
    }

    /** @test */
    public function it_returns_completed_when_all_items_final()
    {
        $pr = $this->createPR([
            'approval_status' => 'approved',
            'return_dispatch_status' => 'dispatched',
        ]);

        $detail = \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::create([
            'purchase_return_id' => $pr->id,
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

        // All items final
        PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'MODIFY_PURCHASE',
            'status' => 'APPROVED',
        ]);

        PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'PRODUCT_REPAIR',
            'status' => 'RECEIVED',
        ]);

        $this->assertEquals(PurchaseReturn::STATUS_COMPLETED, $pr->unified_status);
        $this->assertEquals('Selesai', $pr->unified_status_label);
    }

    /** @test */
    public function it_checks_all_precedence_correctly()
    {
        // 1. Draft
        $pr = new PurchaseReturn(['approval_status' => 'draft']);
        $this->assertEquals(PurchaseReturn::STATUS_DRAFT, $pr->unified_status);

        // 2. Pending Approval
        $pr->approval_status = 'pending';
        $this->assertEquals(PurchaseReturn::STATUS_PENDING_APPROVAL, $pr->unified_status);

        // 3. Rejected
        $pr->approval_status = 'rejected';
        $this->assertEquals(PurchaseReturn::STATUS_REJECTED, $pr->unified_status);

        // 4. Awaiting Dispatch
        $pr->approval_status = 'approved';
        $pr->return_dispatch_status = null;
        $this->assertEquals(PurchaseReturn::STATUS_AWAITING_DISPATCH, $pr->unified_status);

        // 5. Dispatch Pending
        $pr->return_dispatch_status = 'pending_approval';
        $this->assertEquals(PurchaseReturn::STATUS_DISPATCH_PENDING_APPROVAL, $pr->unified_status);

        // 6. In Return
        $pr->return_dispatch_status = 'dispatched';
        $this->assertEquals(PurchaseReturn::STATUS_IN_RETURN, $pr->unified_status);
    }

    /** @test */
    public function it_keeps_document_in_return_status_if_items_are_rejected()
    {
        $pr = $this->createPR([
            'approval_status' => 'approved',
            'return_dispatch_status' => 'dispatched',
        ]);

        $detail = \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::create([
            'purchase_return_id' => $pr->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        // Item is REJECTED (not final)
        PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'MODIFY_PURCHASE',
            'status' => 'REJECTED',
        ]);

        $this->assertEquals(PurchaseReturn::STATUS_IN_RETURN, $pr->unified_status);
    }

    /** @test */
    public function it_shows_partial_settlement_when_some_are_final_and_some_are_rejected()
    {
        $pr = $this->createPR([
            'approval_status' => 'approved',
            'return_dispatch_status' => 'dispatched',
        ]);

        $detail = \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::create([
            'purchase_return_id' => $pr->id,
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

        // One final item (MODIFY_PURCHASE + APPROVED)
        PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'MODIFY_PURCHASE',
            'status' => 'APPROVED',
        ]);

        // One REJECTED item (not final)
        PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'PRODUCT_REPAIR',
            'status' => 'REJECTED',
        ]);

        $this->assertEquals(PurchaseReturn::STATUS_PARTIAL_SETTLEMENT, $pr->unified_status);
    }

    /** @test */
    public function it_returns_completed_regardless_of_due_amount_if_all_items_final()
    {
        $pr = $this->createPR([
            'approval_status' => 'approved',
            'return_dispatch_status' => 'dispatched',
            'due_amount' => 50000, // Non-zero due amount
        ]);

        $detail = \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::create([
            'purchase_return_id' => $pr->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 10000,
            'unit_price' => 10000,
            'sub_total' => 10000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'MODIFY_PURCHASE',
            'status' => 'APPROVED',
        ]);

        $this->assertEquals(PurchaseReturn::STATUS_COMPLETED, $pr->unified_status);
    }
}
