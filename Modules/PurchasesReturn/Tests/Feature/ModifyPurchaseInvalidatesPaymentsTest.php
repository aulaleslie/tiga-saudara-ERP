<?php

namespace Modules\PurchasesReturn\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Location;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\Purchase\Entities\ReceivedNote;
use Modules\Purchase\Entities\ReceivedNoteDetail;
use Modules\Purchase\Entities\PurchasePayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

class ModifyPurchaseInvalidatesPaymentsTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $location;
    protected $supplier;
    protected $product;
    protected $purchase;

    protected function setUp(): void
    {
        parent::setUp();
        
        DB::statement('PRAGMA foreign_keys = OFF');
        
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        
        $permissions = [
            'purchaseReturnSettlements.approve',
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

        $category = \Modules\Product\Entities\Category::create([
            'category_code' => 'CAT001',
            'category_name' => 'Category 1',
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
            'serial_number_required' => false,
        ]);

        $this->purchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-001',
            'supplier_id' => $this->supplier->id,
            'status' => 'APPROVED',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'total_amount' => 10000,
            'paid_amount' => 10000,
            'due_amount' => 0,
            'setting_id' => $this->setting->id,
        ]);

        $pd = PurchaseDetail::create([
            'purchase_id' => $this->purchase->id,
            'product_id' => $this->product->id,
            'quantity' => 10,
            'unit_price' => 1000,
            'price' => 1000,
            'sub_total' => 10000,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $rn = ReceivedNote::create([
            'po_id' => $this->purchase->id,
            'date' => now(),
            'location_id' => $this->location->id,
            'status' => ReceivedNote::STATUS_APPROVED,
        ]);

        ReceivedNoteDetail::create([
            'received_note_id' => $rn->id,
            'po_detail_id' => $pd->id,
            'quantity_received' => 10,
        ]);
        
        // Add a payment
        PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'amount' => 10000,
            'date' => now(),
            'reference' => 'PAY-001',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);
    }

    /** @test */
    public function it_invalidates_payments_instead_of_deleting_on_modify_purchase_with_surplus()
    {
        $pr = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PRRN-001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'total_amount' => 5000, // Returning half
            'paid_amount' => 0,
            'due_amount' => 5000,
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
            'quantity' => 5, // 5 units
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 5000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
            'po_id' => $this->purchase->id,
        ]);

        $item = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'MODIFY_PURCHASE',
            'nominal' => 5000,
            'status' => PurchaseReturnItemSettlement::STATUS_SUBMITTED,
            'target_purchase_id' => $this->purchase->id,
        ]);

        $initialPaymentCount = PurchasePayment::count();
        $this->assertEquals(1, $initialPaymentCount);

        // Approve settlement - this will trigger surplus logic because purchase total becomes 5000, paid was 10000
        $this->post(route('purchase-return-settlements.item.approve', $item->id));

        // ASSERTIONS
        // 1. Row count unchanged (it would fail current implementation which deletes)
        $this->assertEquals($initialPaymentCount, PurchasePayment::count(), 'Payment row was deleted but should have been invalidated');

        // 2. Status is INVALIDATED
        $payment = PurchasePayment::first();
        $this->assertEquals(PurchasePayment::STATUS_INVALIDATED, $payment->status);
        
        // 3. Metadata populated
        $this->assertNotNull($payment->invalidated_at);
        $this->assertEquals($this->user->id, $payment->invalidated_by);
        $this->assertEquals('MODIFY_PURCHASE_SETTLEMENT', $payment->invalidation_source);
        $this->assertEquals($item->id, $payment->invalidation_source_id);
    }

    /** @test */
    public function it_continues_to_succeed_if_no_active_payments_exist()
    {
        // Delete the initial payment to test zero payments case
        PurchasePayment::truncate();

        $pr = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PRRN-002',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'total_amount' => 5000,
            'paid_amount' => 0,
            'due_amount' => 5000,
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
            'quantity' => 5,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 5000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
            'po_id' => $this->purchase->id,
        ]);

        $item = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'MODIFY_PURCHASE',
            'nominal' => 5000,
            'status' => PurchaseReturnItemSettlement::STATUS_SUBMITTED,
            'target_purchase_id' => $this->purchase->id,
        ]);

        $this->post(route('purchase-return-settlements.item.approve', $item->id))
             ->assertSessionHasNoErrors();

        $this->assertEquals(0, PurchasePayment::count());
    }

    /** @test */
    public function it_invalidates_only_active_payments_on_mixed_status_purchase()
    {
        // Add one more payment
        $activePayment = PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'amount' => 5000,
            'date' => now(),
            'reference' => 'PAY-ACTIVE',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_ACTIVE,
        ]);

        // Manually invalidate one
        $alreadyInvalidated = PurchasePayment::create([
            'purchase_id' => $this->purchase->id,
            'amount' => 2000,
            'date' => now(),
            'reference' => 'PAY-PRE-INVALID',
            'payment_method' => 'Cash',
            'status' => PurchasePayment::STATUS_INVALIDATED,
            'invalidation_source' => 'MANUAL',
        ]);

        $pr = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PRRN-003',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'total_amount' => 5000,
            'paid_amount' => 0,
            'due_amount' => 5000,
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
            'quantity' => 5,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 5000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
            'po_id' => $this->purchase->id,
        ]);

        $item = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'MODIFY_PURCHASE',
            'nominal' => 5000,
            'status' => PurchaseReturnItemSettlement::STATUS_SUBMITTED,
            'target_purchase_id' => $this->purchase->id,
        ]);

        $this->post(route('purchase-return-settlements.item.approve', $item->id));

        // Check the newly invalidated payments
        $newlyInvalidated = PurchasePayment::where('status', PurchasePayment::STATUS_INVALIDATED)
            ->where('invalidation_source', 'MODIFY_PURCHASE_SETTLEMENT')
            ->get();
        
        $this->assertEquals(2, $newlyInvalidated->count(), 'All previously active payments should be invalidated');
        
        // Ensure the manually invalidated one kept its source
        $alreadyInvalidated->refresh();
        $this->assertEquals('MANUAL', $alreadyInvalidated->invalidation_source);
    }
}
