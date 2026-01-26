<?php

namespace Modules\PurchasesReturn\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\Transaction;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchaseReturnSettlementDamagedGoodsTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $location;
    protected $targetLocation;
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
            'name' => 'Source Warehouse',
        ]);

        $this->targetLocation = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Target Warehouse',
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

    /** @test */
    public function it_prioritizes_broken_stock_during_dispatch()
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Deduction Product',
            'product_code' => 'P001',
            'product_cost' => 10,
            'product_price' => 20,
            'product_quantity' => 10,
            'broken_quantity' => 5,
            'serial_number_required' => false
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity' => 5,
            'broken_quantity_non_tax' => 5,
            'broken_quantity_tax' => 0,
        ]);

        $pr = PurchaseReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'PRRN-001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'total_amount' => 70,
            'paid_amount' => 0,
            'due_amount' => 70,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'approved',
            'return_dispatch_status' => 'pending_approval',
        ]);

        PurchaseReturnDetail::create([
            'purchase_return_id' => $pr->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 7,
            'price' => 10,
            'unit_price' => 10,
            'sub_total' => 70,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
        ]);

        // Approve dispatch
        $this->post(route('purchase-returns.dispatch-approve', $pr->id));

        $product->refresh();
        $stock = ProductStock::where('product_id', $product->id)->where('location_id', $this->location->id)->first();

        // 7 requested. 5 broken available.
        // Should deduct 5 from broken, 2 from good.
        // product_quantity: 10 - 2 = 8.
        // broken_quantity: 5 - 5 = 0.
        $this->assertEquals(8, $product->product_quantity);
        $this->assertEquals(0, $product->broken_quantity);
        $this->assertEquals(8, $stock->quantity);
        $this->assertEquals(0, $stock->broken_quantity);
    }

    /** @test */
    public function it_handles_broken_stock_return_without_dispatch_prioritizing_broken()
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Settlement Product',
            'product_code' => 'P002',
            'product_cost' => 10,
            'product_price' => 20,
            'product_quantity' => 10,
            'broken_quantity' => 5,
            'serial_number_required' => false
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity' => 5,
            'broken_quantity_non_tax' => 5,
            'broken_quantity_tax' => 0,
        ]);

        $pr = PurchaseReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'PRRN-002',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'total_amount' => 70,
            'paid_amount' => 0,
            'due_amount' => 70,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'approved',
        ]);

        $detail = PurchaseReturnDetail::create([
            'purchase_return_id' => $pr->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 7,
            'price' => 10,
            'unit_price' => 10,
            'sub_total' => 70,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
        ]);

        $item = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'BROKEN_STOCK',
            'nominal' => 70,
            'status' => 'APPROVED_AWAITING_RECEIVE',
        ]);

        // Receive settlement at target location
        $this->post(route('purchase-return-settlements.item.receive', $item->id), [
            'location_id' => $this->targetLocation->id,
            'received_quantity' => 7,
        ]);

        $product->refresh();
        $sourceStock = ProductStock::where('product_id', $product->id)->where('location_id', $this->location->id)->first();
        $targetStock = ProductStock::where('product_id', $product->id)->where('location_id', $this->targetLocation->id)->first();

        // 7 requested as BROKEN. 5 broken available at source.
        $this->assertEquals(8, $product->product_quantity);
        $this->assertEquals(7, $product->broken_quantity);
        $this->assertEquals(8, $sourceStock->quantity);
        $this->assertEquals(0, $sourceStock->broken_quantity);
        $this->assertEquals(7, $targetStock->broken_quantity);
    }

    /** @test */
    public function it_handles_broken_stock_return_with_dispatch()
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Dispatched Product',
            'product_code' => 'P003',
            'product_cost' => 10,
            'product_price' => 20,
            'product_quantity' => 10,
            'broken_quantity' => 5,
            'serial_number_required' => false
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity' => 5,
            'broken_quantity_non_tax' => 5,
            'broken_quantity_tax' => 0,
        ]);

        $pr = PurchaseReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'PRRN-003',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'total_amount' => 70,
            'paid_amount' => 0,
            'due_amount' => 70,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'approved',
            'return_dispatch_status' => 'pending_approval',
        ]);

        $detail = PurchaseReturnDetail::create([
            'purchase_return_id' => $pr->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 7,
            'price' => 10,
            'unit_price' => 10,
            'sub_total' => 70,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
        ]);

        // 1. Approve dispatch
        $this->post(route('purchase-returns.dispatch-approve', $pr->id));

        $item = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => 'BROKEN_STOCK',
            'nominal' => 70,
            'status' => 'APPROVED_AWAITING_RECEIVE',
        ]);

        // 2. Receive settlement
        $this->post(route('purchase-return-settlements.item.receive', $item->id), [
            'location_id' => $this->targetLocation->id,
            'received_quantity' => 7,
        ]);

        $product->refresh();
        $targetStock = ProductStock::where('product_id', $product->id)->where('location_id', $this->targetLocation->id)->first();

        $this->assertEquals(8, $product->product_quantity);
        $this->assertEquals(7, $product->broken_quantity);
        $this->assertEquals(0, $targetStock->quantity);
        $this->assertEquals(7, $targetStock->broken_quantity);
    }

    /** @test */
    public function it_handles_serial_item_broken_stock_return()
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Serial Product',
            'product_code' => 'P004',
            'product_cost' => 100,
            'product_price' => 200,
            'product_quantity' => 1,
            'broken_quantity' => 0,
            'serial_number_required' => true
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 1,
            'quantity_non_tax' => 1,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $sn = ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => 'SN-BROKEN-TEST',
            'status' => 'ACTIVE',
            'location_id' => $this->location->id,
            'is_broken' => false,
        ]);

        $pr = PurchaseReturn::create([
            'date' => now()->toDateString(),
            'reference' => 'PRRN-004',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'total_amount' => 100,
            'paid_amount' => 0,
            'due_amount' => 100,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'approved',
        ]);

        $detail = PurchaseReturnDetail::create([
            'purchase_return_id' => $pr->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 100,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
            'serial_number_ids' => [$sn->id],
        ]);

        $item = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $pr->id,
            'purchase_return_detail_id' => $detail->id,
            'product_serial_number_id' => $sn->id,
            'method' => 'BROKEN_STOCK',
            'nominal' => 100,
            'status' => 'APPROVED_AWAITING_RECEIVE',
        ]);

        // Receive settlement
        $this->post(route('purchase-return-settlements.item.receive', $item->id), [
            'location_id' => $this->targetLocation->id,
            'received_quantity' => 1,
        ]);

        $sn->refresh();
        $this->assertTrue((bool)$sn->is_broken);
        $this->assertEquals('BROKEN', $sn->status);
        $this->assertEquals($this->targetLocation->id, $sn->location_id);
        $this->assertFalse((bool)$sn->is_in_return_process);

        $product->refresh();
        $this->assertEquals(0, $product->product_quantity);
        $this->assertEquals(1, $product->broken_quantity);
    }
}
