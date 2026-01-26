<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Purchase\Entities\Purchase;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
use Modules\Setting\Entities\Location;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Tests\TestCase;

class PurchaseReturnSettlementExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $supplier;
    protected $location;
    protected $targetLocation;
    protected $product;
    protected $purchaseReturn;
    protected $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        
        Gate::define('purchaseReturnSettlements.receive', fn() => true);

        $currency = Currency::create([
            'currency_name'       => 'Rupiah',
            'code'                => 'IDR',
            'symbol'              => 'Rp',
            'thousand_separator'  => '.',
            'decimal_separator'   => ',',
            'exchange_rate'       => 1,
        ]);

        $this->setting = Setting::create([
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
            'name' => 'Source Warehouse',
            'setting_id' => $this->setting->id,
        ]);

        $this->targetLocation = Location::create([
            'name' => 'Target Warehouse',
            'setting_id' => $this->setting->id,
        ]);

        $category = Category::create([
            'category_name' => 'Test Category',
            'category_code' => 'TC01',
            'setting_id' => $this->setting->id,
            'created_by' => $this->user->id,
        ]);

        $this->product = Product::create([
            'product_name' => 'Test Product',
            'product_code' => 'TP001',
            'product_unit' => 'PCS',
            'product_price' => 1000,
            'product_cost' => 800,
            'category_id' => $category->id,
            'product_order_tax' => 0,
            'product_tax_type' => 1,
            'product_note' => 'Note',
            'setting_id' => $this->setting->id,
        ]);

        $this->purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PR-001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'status' => 'Pending',
            'approval_status' => 'Approved',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
        ]);
    }

    /** @test */
    public function it_repairs_product_with_same_serial_number_restoring_status()
    {
        $serial = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-REP-01',
            'status' => 'RETURNED',
            'is_broken' => true,
            'is_in_return_process' => true,
        ]);

        $detail = PurchaseReturnDetail::create([
            'purchase_return_id' => $this->purchaseReturn->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TP001',
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
        ]);

        $itemSettlement = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $this->purchaseReturn->id,
            'purchase_return_detail_id' => $detail->id,
            'product_serial_number_id' => $serial->id,
            'method' => 'PRODUCT_REPAIR',
            'status' => PurchaseReturnItemSettlement::STATUS_APPROVED_AWAITING_RECEIVE,
            'nominal' => 0,
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('purchase-return-settlements.item.receive', $itemSettlement), [
                'location_id' => $this->targetLocation->id,
                'received_quantity' => 1,
                'replacement_serial_number' => 'SN-REP-01',
                'note' => 'Repair same serial',
            ]);

        $response->assertSessionHas('success');
        
        $serial->refresh();
        $this->assertEquals('AVAILABLE', $serial->status);
        $this->assertFalse($serial->is_broken);
        $this->assertFalse($serial->is_in_return_process);
        $this->assertEquals($this->targetLocation->id, $serial->location_id);
    }

    /** @test */
    public function it_replaces_product_with_different_serial_number_inplace()
    {
        $originalId = 0;
        $serial = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-OLD-01',
            'status' => 'RETURNED',
            'is_broken' => true,
            'is_in_return_process' => true,
        ]);
        $originalId = $serial->id;

        $detail = PurchaseReturnDetail::create([
            'purchase_return_id' => $this->purchaseReturn->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TP001',
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
        ]);

        $itemSettlement = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $this->purchaseReturn->id,
            'purchase_return_detail_id' => $detail->id,
            'product_serial_number_id' => $serial->id,
            'method' => 'PRODUCT_REPAIR',
            'status' => PurchaseReturnItemSettlement::STATUS_APPROVED_AWAITING_RECEIVE,
            'nominal' => 0,
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('purchase-return-settlements.item.receive', $itemSettlement), [
                'location_id' => $this->targetLocation->id,
                'received_quantity' => 1,
                'replacement_serial_number' => 'SN-NEW-999',
                'note' => 'Replacement different serial',
            ]);

        $response->assertSessionHas('success');
        
        $serial->refresh();
        $this->assertEquals($originalId, $serial->id, "Serial ID must remain the same (In-place update)");
        $this->assertEquals('SN-NEW-999', $serial->serial_number);
        $this->assertEquals('AVAILABLE', $serial->status);
        $this->assertFalse($serial->is_broken);
        $this->assertFalse($serial->is_in_return_process);
        $this->assertEquals($this->targetLocation->id, $serial->location_id);
    }

    /** @test */
    public function it_fails_replacement_if_new_serial_already_exists()
    {
        ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-EXISTS-01',
            'status' => 'AVAILABLE',
        ]);

        $serial = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-OLD-02',
            'status' => 'RETURNED',
            'is_in_return_process' => true,
        ]);

        $detail = PurchaseReturnDetail::create([
            'purchase_return_id' => $this->purchaseReturn->id,
            'product_id' => $this->product->id,
            'product_name' => 'Test Product',
            'product_code' => 'TP001',
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
        ]);

        $itemSettlement = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $this->purchaseReturn->id,
            'purchase_return_detail_id' => $detail->id,
            'product_serial_number_id' => $serial->id,
            'method' => 'PRODUCT_REPAIR',
            'status' => PurchaseReturnItemSettlement::STATUS_APPROVED_AWAITING_RECEIVE,
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('purchase-return-settlements.item.receive', $itemSettlement), [
                'location_id' => $this->targetLocation->id,
                'received_quantity' => 1,
                'replacement_serial_number' => 'SN-EXISTS-01',
                'note' => 'Replacement with existing serial',
            ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('sudah terdaftar', session('error'));
    }
}
