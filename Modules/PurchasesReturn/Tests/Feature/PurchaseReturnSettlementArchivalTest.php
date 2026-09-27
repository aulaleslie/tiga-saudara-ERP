<?php

namespace Modules\PurchasesReturn\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use App\Models\User;

class PurchaseReturnSettlementArchivalTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $location;
    protected $product;

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
        
        session(['setting_id' => $this->setting->id]);

        $this->location = Location::create([
            'name' => 'Test Warehouse',
            'setting_id' => $this->setting->id,
        ]);

        \Modules\People\Entities\Supplier::create([
            'id' => 1,
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@example.com',
            'supplier_phone' => '12345678',
            'city' => 'Jakarta',
            'country' => 'Indonesia',
            'address' => 'Test Address',
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
            'product_code' => 'P001',
            'product_unit' => 'pcs',
            'product_price' => 1000,
            'product_cost' => 800,
            'category_id' => $category->id,
            'setting_id' => $this->setting->id,
            'product_quantity' => 10,
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
    }

    /** @test */
    public function test_modify_purchase_archives_when_all_items_returned()
    {
        // 1. Create a purchase
        $purchase = \Modules\Purchase\Entities\Purchase::create([
            'supplier_id' => 1,
            'reference' => 'PO-001',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'status' => 'Received',
            'date' => now(),
            'due_date' => now()->addDays(7),
            'setting_id' => $this->setting->id,
        ]);

        $detail = \Modules\Purchase\Entities\PurchaseDetail::create([
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

        $rn = \Modules\Purchase\Entities\ReceivedNote::create([
            'po_id' => $purchase->id,
            'status' => 'APPROVED',
            'date' => now(),
            'location_id' => $this->location->id,
        ]);

        \Modules\Purchase\Entities\ReceivedNoteDetail::create([
            'received_note_id' => $rn->id,
            'po_detail_id' => $detail->id,
            'product_id' => $this->product->id,
            'quantity_received' => 1,
        ]);

        // 2. Create PR and MODIFY_PURCHASE settlement
        $pr = PurchaseReturn::create([
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'payment_method' => 'Cash',
            'payment_status' => 'Unpaid',
            'reference' => 'PR-ARCHIVE-001',
            'status' => 'Pending',
            'location_id' => $this->location->id,
            'setting_id' => $this->setting->id,
            'date' => now(),
        ]);

        $prDetail = PurchaseReturnDetail::create([
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
            'purchase_return_detail_id' => $prDetail->id,
            'method' => 'MODIFY_PURCHASE',
            'nominal' => 1000,
            'status' => PurchaseReturnItemSettlement::STATUS_SUBMITTED,
            'target_purchase_id' => $purchase->id
        ]);

        // 3. Approve settlement
        $this->post(route('purchase-return-settlements.item.approve', $item->id));

        $purchase->refresh();
        
        // Assertions
        $this->assertNotNull($purchase->archived_at);
        $this->assertEquals($this->user->id, $purchase->archived_by);
        $this->assertStringContainsString("Barang sudah diretur {$pr->reference}", $purchase->note);
        $this->assertEquals(0, $purchase->purchaseDetails()->sum('quantity'));
    }

    /** @test */
    public function test_modify_purchase_resets_payments_for_paid_purchases()
    {
        // 1. Create a PAID purchase
        $purchase = \Modules\Purchase\Entities\Purchase::create([
            'supplier_id' => 1,
            'reference' => 'PO-PAID-001',
            'total_amount' => 5000,
            'paid_amount' => 5000,
            'due_amount' => 0,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'status' => 'Received',
            'date' => now(),
            'due_date' => now()->addDays(7),
            'setting_id' => $this->setting->id,
        ]);

        $detail = \Modules\Purchase\Entities\PurchaseDetail::create([
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

        $rn = \Modules\Purchase\Entities\ReceivedNote::create([
            'po_id' => $purchase->id,
            'status' => 'APPROVED',
            'date' => now(),
            'location_id' => $this->location->id,
        ]);

        \Modules\Purchase\Entities\ReceivedNoteDetail::create([
            'received_note_id' => $rn->id,
            'po_detail_id' => $detail->id,
            'product_id' => $this->product->id,
            'quantity_received' => 5,
        ]);

        \Modules\Purchase\Entities\PurchasePayment::create([
            'purchase_id' => $purchase->id,
            'amount' => 5000,
            'date' => now(),
            'payment_method' => 'Cash',
            'reference' => 'PAY-001',
        ]);

        // 2. Create PR and MODIFY_PURCHASE settlement for 2 units
        $pr = PurchaseReturn::create([
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'payment_method' => 'Cash',
            'payment_status' => 'Unpaid',
            'reference' => 'PR-PARTIAL-001',
            'status' => 'Pending',
            'location_id' => $this->location->id,
            'setting_id' => $this->setting->id,
            'date' => now(),
        ]);

        $prDetail = PurchaseReturnDetail::create([
            'purchase_return_id' => $pr->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 2,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 2000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
        ]);

        $item = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $prDetail->id,
            'method' => 'MODIFY_PURCHASE',
            'nominal' => 2000,
            'status' => PurchaseReturnItemSettlement::STATUS_SUBMITTED,
            'target_purchase_id' => $purchase->id
        ]);

        // 3. Approve settlement
        $this->post(route('purchase-return-settlements.item.approve', $item->id));

        $purchase->refresh();
        
        // Assertions
        $this->assertEquals(0, $purchase->paid_amount);
        $this->assertEquals('UNPAID', strtoupper($purchase->payment_status));
        $this->assertEquals(0, $purchase->purchasePayments()->active()->count(), 'Found active payments but expected none');
        $this->assertEquals(1, $purchase->purchasePayments()->invalidated()->count(), 'Expected 1 invalidated payment');
        $this->assertEquals(3000, $purchase->total_amount); // 5000 - 2000
        $this->assertNull($purchase->archived_at); // Not fully returned
    }
}
