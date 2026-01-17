<?php

namespace Modules\PurchasesReturn\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
use Modules\PurchasesReturn\Entities\PurchaseReturnPayment;
use Modules\PurchasesReturn\Entities\SupplierCredit;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchaseReturnItemApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $location;
    protected $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        \Illuminate\Support\Facades\Gate::before(fn () => true);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '123456',
            'company_address' => 'Test Address',
            'notification_email' => 'test@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'footer',
        ]);

        $this->location = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Main Warehouse',
        ]);

        $this->supplier = Supplier::create([
            'setting_id' => $this->setting->id,
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'supplier@example.com',
            'supplier_phone' => '123456',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);
    }

    private function createPurchaseReturn($total = 1000)
    {
        \Illuminate\Support\Facades\DB::table('purchase_returns')->insert([
            'date' => now()->toDateString(),
            'reference' => 'PRRN-' . uniqid(),
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'total_amount' => $total,
            'paid_amount' => 0,
            'due_amount' => $total,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return PurchaseReturn::latest('id')->first();
    }

    private function createDetail($purchaseReturn, $subTotal = 1000)
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Product',
            'product_code' => 'P' . uniqid(),
            'product_cost' => 10,
            'product_price' => 20,
            'serial_number_required' => false
        ]);

        return PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => $subTotal,
            'unit_price' => $subTotal,
            'sub_total' => $subTotal,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
        ]);
    }

    /** @test */
    public function it_can_approve_submitted_cash_line_and_create_payment()
    {
        $pr = $this->createPurchaseReturn(1000);
        $detail = $this->createDetail($pr, 1000);
        $item = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'CASH',
            'nominal' => 1000,
            'status' => 'SUBMITTED',
        ]);

        $response = $this->post(route('purchase-return-settlements.item.approve', $item->id));

        $response->assertStatus(302);
        $this->assertEquals('APPROVED', $item->fresh()->status);
        $this->assertDatabaseHas('purchase_return_payments', [
            'purchase_return_id' => $pr->id,
            'amount' => 1000,
        ]);
        $this->assertEquals('SETTLED', $pr->fresh()->status);
    }

    /** @test */
    public function it_can_approve_submitted_credit_line_and_create_supplier_credit()
    {
        $pr = $this->createPurchaseReturn(1000);
        $detail = $this->createDetail($pr, 1000);
        $item = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'CREDIT',
            'nominal' => 1000,
            'status' => 'SUBMITTED',
        ]);

        $response = $this->post(route('purchase-return-settlements.item.approve', $item->id));

        $response->assertStatus(302);
        $this->assertEquals('APPROVED', $item->fresh()->status);
        $this->assertDatabaseHas('supplier_credits', [
            'purchase_return_id' => $pr->id,
            'amount' => 1000,
            'remaining_amount' => 1000,
        ]);
    }

    /** @test */
    public function it_can_approve_submitted_modify_purchase_line_and_update_purchase()
    {
        $purchase = Purchase::create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'reference' => 'PS-' . uniqid(),
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'total_amount' => 5000,
            'paid_amount' => 1000,
            'due_amount' => 4000,
            'status' => 'Received',
            'payment_status' => 'Partial',
            'payment_method' => 'Cash',
        ]);

        $pr = $this->createPurchaseReturn(1000);
        $detail = $this->createDetail($pr, 1000);
        $item = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'MODIFY_PURCHASE',
            'nominal' => 1000,
            'status' => 'SUBMITTED',
            'target_purchase_id' => $purchase->id,
        ]);

        $response = $this->post(route('purchase-return-settlements.item.approve', $item->id));

        $response->assertStatus(302);
        $this->assertEquals('APPROVED', $item->fresh()->status);
        
        $purchase = $purchase->fresh();
        $this->assertEquals(3000, (float) $purchase->due_amount);
        $this->assertEquals(2000, (float) $purchase->paid_amount);
    }

    /** @test */
    public function it_blocks_approval_if_nominal_exceeds_subtotal()
    {
        $pr = $this->createPurchaseReturn(1000);
        $detail = $this->createDetail($pr, 1000);
        $item = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'CASH',
            'nominal' => 1500, // exceeds 1000
            'status' => 'SUBMITTED',
        ]);

        $response = $this->post(route('purchase-return-settlements.item.approve', $item->id));

        $response->assertStatus(302);
        $response->assertSessionHas('error', 'Gagal menyetujui item: Nominal penyelesaian melebihi subtotal item.');
        $this->assertEquals('SUBMITTED', $item->fresh()->status);
    }

    /** @test */
    public function it_rejects_line_and_clears_method()
    {
        $pr = $this->createPurchaseReturn(1000);
        $detail = $this->createDetail($pr, 1000);
        $item = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'CASH',
            'nominal' => 1000,
            'status' => 'SUBMITTED',
        ]);

        $response = $this->post(route('purchase-return-settlements.item.reject', $item->id), [
            'rejection_reason' => 'Invalid nominal',
        ]);

        $response->assertStatus(302);
        $item = $item->fresh();
        $this->assertEquals('REJECTED', $item->status);
        $this->assertNull($item->method);
        $this->assertEquals(0, (float) $item->nominal);
        $this->assertEquals('INVALID NOMINAL', $item->rejection_reason);
    }

    /** @test */
    public function it_blocks_double_approval()
    {
        $pr = $this->createPurchaseReturn(1000);
        $detail = $this->createDetail($pr, 1000);
        $item = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'CASH',
            'nominal' => 1000,
            'status' => 'SUBMITTED',
        ]);

        $this->post(route('purchase-return-settlements.item.approve', $item->id));
        
        $response = $this->post(route('purchase-return-settlements.item.approve', $item->id));
        $response->assertStatus(302);
        $this->assertEquals('Item ini tidak dapat disetujui.', session('error'));
    }

    /** @test */
    public function it_increments_existing_payment_instead_of_creating_new()
    {
        $pr = $this->createPurchaseReturn(2000);
        $detail1 = $this->createDetail($pr, 1000);
        $detail2 = $this->createDetail($pr, 1000);
        
        $item1 = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail1->id,
            'method' => 'CASH',
            'nominal' => 1000,
            'status' => 'SUBMITTED',
        ]);
        
        $item2 = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail2->id,
            'method' => 'CASH',
            'nominal' => 1000,
            'status' => 'SUBMITTED',
        ]);

        $this->post(route('purchase-return-settlements.item.approve', $item1->id));
        $this->post(route('purchase-return-settlements.item.approve', $item2->id));

        $this->assertEquals(1, PurchaseReturnPayment::where('purchase_return_id', $pr->id)->count());
        $this->assertEquals(2000, (float) PurchaseReturnPayment::where('purchase_return_id', $pr->id)->first()->amount);
    }
}
