<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Product\Entities\Product;
use Modules\Purchase\Entities\Purchase;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
use Modules\PurchasesReturn\Entities\SupplierCredit;
use Modules\Setting\Entities\Location;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\ProductSerialNumber;
use Tests\TestCase;

class PurchaseReturnSettlementSimpanDPTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $supplier;
    protected $location;
    protected $product;
    protected $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Gate::define('purchaseReturnSettlements.submit', fn() => true);
        Gate::define('purchaseReturns.viewPrice', fn() => true);
        Gate::define('purchaseReturnSettlements.approve', fn() => true);

        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name'       => 'Rupiah',
            'code'                => 'IDR',
            'symbol'              => 'Rp',
            'thousand_separator'  => '.',
            'decimal_separator'   => ',',
            'exchange_rate'       => 1,
        ]);

        $this->setting = \Modules\Setting\Entities\Setting::create([
            'company_name'              => 'Test Company',
            'company_email'             => 'test@example.com',
            'company_phone'             => '1234567890',
            'default_currency_id'       => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email'        => 'test@example.com',
            'footer_text'               => 'Footer',
            'company_address'           => '123 Street',
        ]);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@example.com',
            'supplier_phone' => '123456789',
            'address' => 'Test Address',
            'city' => 'Test City',
            'country' => 'Test Country',
            'setting_id' => $this->setting->id,
        ]);

        $this->location = Location::create([
            'name' => 'Main Warehouse',
            'setting_id' => $this->setting->id,
        ]);

        $this->product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TP001',
            'product_unit' => 'PCS',
            'product_price' => 1000,
            'product_cost' => 800,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
            'setting_id' => $this->setting->id,
            'serial_number_required' => true,
        ]);
    }

    /** @test */
    public function it_shows_simpan_as_dp_for_serial_item_if_origin_purchase_is_paid_and_target_exists()
    {
        // 1. Origin Purchase - PAID
        $originPurchase = Purchase::create([
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'date' => now(),
            'due_date' => now(),
            'setting_id' => $this->setting->id,
        ]);

        $serial = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'serial_number' => 'SN-PAID-01',
            'status' => 'RETURNED',
            'location_id' => $this->location->id,
        ]);

        // Mock the relation for component loading
        $rn = \Modules\Purchase\Entities\ReceivedNote::create([
            'purchase_id' => $originPurchase->id, 
            'po_id' => $originPurchase->id, 
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'date' => now(),
        ]);
        $pd = $originPurchase->purchaseDetails()->create([
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

        $rnd = \Modules\Purchase\Entities\ReceivedNoteDetail::create([
            'received_note_id' => $rn->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => 1,
            'quantity_received' => 1,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'po_detail_id' => $pd->id,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
        ]);
        $rnd->update(['purchase_detail_id' => $pd->id]);
        $serial->update(['received_note_detail_id' => $rnd->id]);

        // 2. Target Purchase - UNPAID
        Purchase::create([
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'total_amount' => 5000,
            'paid_amount' => 0,
            'due_amount' => 5000,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'payment_method' => 'Cash',
            'date' => now(),
            'due_date' => now(),
            'setting_id' => $this->setting->id,
        ]);

        // 3. Purchase Return
        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'paid_amount' => 0,
            'due_amount' => 500, // This will be overwritten by specific tests anyway
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'setting_id' => $this->setting->id,
            'status' => 'Pending',
            'approval_status' => 'Approved',
        ]);

        $purchaseReturn->purchaseReturnDetails()->create([
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'serial_number_ids' => [$serial->id],
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\PurchaseReturn\PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->assertSee('Simpan Sebagai DP');
    }

    /** @test */
    public function it_hides_simpan_as_dp_for_serial_item_if_origin_purchase_is_unpaid()
    {
        // 1. Origin Purchase - UNPAID
        $originPurchase = Purchase::create([
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'payment_method' => 'Cash',
            'date' => now(),
            'due_date' => now(),
            'setting_id' => $this->setting->id,
        ]);

        $serial = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'serial_number' => 'SN-UNPAID-01',
            'status' => 'RETURNED',
            'location_id' => $this->location->id,
        ]);

        $rn = \Modules\Purchase\Entities\ReceivedNote::create([
            'purchase_id' => $originPurchase->id, 
            'po_id' => $originPurchase->id,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'date' => now(),
        ]);
        $pd = $originPurchase->purchaseDetails()->create([
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

        $rnd = \Modules\Purchase\Entities\ReceivedNoteDetail::create([
            'received_note_id' => $rn->id, 
            'product_id' => $this->product->id, 
            'quantity' => 1,
            'quantity_received' => 1,
            'location_id' => $this->location->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'po_detail_id' => $pd->id,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
        ]);
        $rnd->update(['purchase_detail_id' => $pd->id]);
        $serial->update(['received_note_detail_id' => $rnd->id]);

        // 2. Target Purchase - UNPAID
        Purchase::create([
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'total_amount' => 5000,
            'paid_amount' => 0,
            'due_amount' => 5000,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'payment_method' => 'Cash',
            'date' => now(),
            'due_date' => now(),
            'setting_id' => $this->setting->id,
        ]);

        // 3. Purchase Return
        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'paid_amount' => 0,
            'due_amount' => 500, // This will be overwritten by specific tests anyway
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'setting_id' => $this->setting->id,
            'status' => 'Pending',
            'approval_status' => 'Approved',
        ]);

        $purchaseReturn->purchaseReturnDetails()->create([
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'serial_number_ids' => [$serial->id],
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\PurchaseReturn\PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->assertDontSee('Simpan Sebagai DP');
    }

    /** @test */
    public function it_hides_simpan_as_dp_for_non_serial_if_active_unpaid_purchase_with_product_exists()
    {
        $productNonSerial = Product::create([
            'product_name' => 'Non Serial',
            'product_code' => 'NS01',
            'product_unit' => 'PCS',
            'product_cost' => 100,
            'product_price' => 200,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
            'setting_id' => $this->setting->id,
            'serial_number_required' => false,
        ]);

        // 1. Purchase with product - UNPAID
        $purchaseWithProduct = Purchase::create([
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'payment_method' => 'Cash',
            'date' => now(),
            'due_date' => now(),
            'setting_id' => $this->setting->id,
        ]);
        $purchaseWithProduct->purchaseDetails()->create([
            'product_id' => $productNonSerial->id,
            'product_name' => $productNonSerial->product_name,
            'product_code' => $productNonSerial->product_code,
            'quantity' => 10,
            'price' => 200,
            'unit_price' => 200,
            'sub_total' => 2000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        // 2. Another Target Purchase - UNPAID (No product)
        Purchase::create([
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'total_amount' => 5000,
            'paid_amount' => 0,
            'due_amount' => 5000,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'payment_method' => 'Cash',
            'date' => now(),
            'due_date' => now(),
            'setting_id' => $this->setting->id,
        ]);

        // 3. Purchase Return for 1 unit
        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'paid_amount' => 0,
            'due_amount' => 500, // This will be overwritten by specific tests anyway
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'total_amount' => 200,
            'setting_id' => $this->setting->id,
            'status' => 'Pending',
            'approval_status' => 'Approved',
        ]);

        $purchaseReturn->purchaseReturnDetails()->create([
            'product_id' => $productNonSerial->id,
            'product_name' => $productNonSerial->product_name,
            'product_code' => $productNonSerial->product_code,
            'quantity' => 1,
            'price' => 200,
            'unit_price' => 200,
            'sub_total' => 200,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        Livewire::actingAs($this->user)
            ->test(\App\Livewire\PurchaseReturn\PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->assertDontSee('Simpan Sebagai DP');
    }

    /** @test */
    public function it_caps_credit_application_at_target_due_amount_and_keeps_remainder()
    {
        // 1. Setup Supplier and Target Purchase
        $targetPurchase = Purchase::create([
            'supplier_id' => $this->supplier->id,
            'status' => Purchase::STATUS_RECEIVED,
            'reference' => 'TARGET-001',
            'total_amount' => 300,
            'paid_amount' => 0,
            'due_amount' => 300,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'payment_method' => 'Cash',
            'date' => now(),
            'due_date' => now(),
            'setting_id' => $this->setting->id,
        ]);

        // 2. Setup Purchase Return with 500 nominal (Return > Target Due)
        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'paid_amount' => 0,
            'due_amount' => 500, // This will be overwritten by specific tests anyway
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'reference' => 'RETUR-500',
            'total_amount' => 500,
            'setting_id' => $this->setting->id,
            'status' => 'Pending',
        ]);
        $detail = $purchaseReturn->purchaseReturnDetails()->create([
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => 1,
            'price' => 500,
            'unit_price' => 500,
            'sub_total' => 500,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $itemSettlement = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $purchaseReturn->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'CREDIT',
            'nominal' => 500,
            'target_purchase_id' => $targetPurchase->id,
            'status' => PurchaseReturnItemSettlement::STATUS_SUBMITTED,
        ]);

        // 3. Approve and Execute
        $response = $this->actingAs($this->user)
            ->post(route('purchase-return-settlements.item.approve', $itemSettlement), [
                'approval_note' => 'Cap test'
            ]);

        $response->assertSessionHas('success');

        // 4. Verify Effects
        $targetPurchase->refresh();
        $this->assertEquals(300, $targetPurchase->paid_amount);
        $this->assertEquals(0, $targetPurchase->due_amount);
        $this->assertEquals('PAID', strtoupper($targetPurchase->payment_status));

        $credit = SupplierCredit::where('purchase_return_id', $purchaseReturn->id)->first();
        $this->assertNotNull($credit);
        $this->assertEquals(500, $credit->amount);
        $this->assertEquals(200, $credit->remaining_amount); // 500 - 300 capped
        $this->assertEquals('OPEN', strtoupper($credit->status));

        // Verify Payment Application
        $application = \Modules\PurchasesReturn\Entities\PurchasePaymentCreditApplication::where('supplier_credit_id', $credit->id)->first();
        $this->assertEquals(300, $application->amount);
    }
}
