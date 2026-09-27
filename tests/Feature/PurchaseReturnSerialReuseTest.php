<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckUserRoleForSetting;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Location;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
use Tests\TestCase;

class PurchaseReturnSerialReuseTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Setting $setting;
    private Currency $currency;
    private Supplier $supplier;
    private Location $location;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            CheckUserRoleForSetting::class,
            VerifyCsrfToken::class,
        ]);

        $this->currency = Currency::create([
            'id'                  => 1,
            'currency_name'       => 'Rupiah',
            'code'                => 'IDR',
            'symbol'              => 'Rp',
            'thousand_separator'  => '.',
            'decimal_separator'   => ',',
            'exchange_rate'       => 1,
        ]);

        $this->setting = Setting::create([
            'id'                        => 1,
            'company_name'              => 'Test Company',
            'company_email'             => 'test@example.com',
            'company_phone'             => '1234567890',
            'default_currency_id'       => 1,
            'default_currency_position' => 'prefix',
            'notification_email'        => 'test@example.com',
            'footer_text'               => 'Footer',
            'company_address'           => '123 Street',
        ]);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        
        $this->supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'test@supplier.com',
            'supplier_phone' => '1234567890',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
            'setting_id' => 1,
        ]);
        
        $this->location = Location::create([
            'name' => 'Main Warehouse',
            'setting_id' => 1
        ]);

        $category = Category::create([
            'category_name' => 'Electronics',
            'category_code' => 'ELEC',
            'setting_id' => 1,
            'created_by' => $this->user->id,
        ]);

        $this->product = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Serialized Laptop',
            'product_code' => 'LAP01',
            'product_quantity' => 10,
            'product_cost' => 5000000,
            'product_price' => 7000000,
            'setting_id' => 1,
            'serial_number_required' => true,
        ]);

        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        session(['setting_id' => 1]);

        \Illuminate\Support\Facades\Gate::define('purchaseReturnSettlements.receive', fn() => true);
        \Illuminate\Support\Facades\Gate::define('purchaseReturnSettlements.approve', fn() => true);
    }

    private function createPurchaseReturn($total = 7000000)
    {
        return PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PRRN-' . uniqid(),
            'setting_id' => 1,
            'location_id' => $this->location->id,
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'payment_method' => 'Cash',
            'tax_percentage' => 0,
            'discount_percentage' => 0,
            'shipping_amount' => 0,
            'paid_amount' => 0,
            'total_amount' => $total,
            'due_amount' => $total,
            'status' => 'Awaiting Settlement',
            'approval_status' => 'Approved',
            'payment_status' => 'Unpaid',
            'return_dispatched_at' => now(),
        ]);
    }

    private function createReturnDetail($returnId, $qty = 1, $price = 7000000)
    {
        return PurchaseReturnDetail::create([
            'purchase_return_id' => $returnId,
            'product_id' => $this->product->id,
            'product_name' => $this->product->product_name,
            'product_code' => $this->product->product_code,
            'quantity' => $qty,
            'price' => $price,
            'unit_price' => $price,
            'sub_total' => $qty * $price,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
        ]);
    }

    /**
     * Test that a 'returned' status serial number can be used as a replacement.
     */
    public function test_can_receive_returned_serial_as_replacement()
    {
        $serialA = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SERIAL-A',
            'status' => 'RETURNED',
            'is_in_return_process' => false,
        ]);

        $serialB = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SERIAL-B',
            'status' => 'active',
            'is_in_return_process' => true,
        ]);

        $purchaseReturn = $this->createPurchaseReturn(7000000);
        $detail = $this->createReturnDetail($purchaseReturn->id, 1, 7000000);

        $itemSettlement = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $purchaseReturn->id,
            'purchase_return_detail_id' => $detail->id,
            'product_serial_number_id' => $serialB->id,
            'method' => 'PRODUCT_REPAIR',
            'status' => PurchaseReturnItemSettlement::STATUS_APPROVED_AWAITING_RECEIVE,
            'nominal' => 7000000,
        ]);

        $response = $this->post(route('purchase-return-settlements.item.receive', $itemSettlement), [
            'location_id' => $this->location->id,
            'received_quantity' => 1,
            'replacement_serial_number' => 'SERIAL-A',
            'note' => 'Replacement with Serial A',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $serialA->refresh();
        $this->assertEquals('ACTIVE', $serialA->status);
        
        $serialB->refresh();
        $this->assertEquals('RETURNED', $serialB->status);
        $this->assertFalse($serialB->is_in_return_process);
    }

    /**
     * Test that an 'active' serial number cannot be used as a replacement.
     */
    public function test_cannot_receive_active_serial_as_replacement()
    {
        ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SERIAL-C',
            'status' => 'active',
            'is_in_return_process' => false,
        ]);

        $serialB = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SERIAL-B',
            'status' => 'active',
            'is_in_return_process' => true,
        ]);

        $purchaseReturn = $this->createPurchaseReturn(7000000);
        $detail = $this->createReturnDetail($purchaseReturn->id, 1, 7000000);

        $itemSettlement = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $purchaseReturn->id,
            'purchase_return_detail_id' => $detail->id,
            'product_serial_number_id' => $serialB->id,
            'method' => 'PRODUCT_REPAIR',
            'status' => PurchaseReturnItemSettlement::STATUS_APPROVED_AWAITING_RECEIVE,
        ]);

        $response = $this->post(route('purchase-return-settlements.item.receive', $itemSettlement), [
            'location_id' => $this->location->id,
            'received_quantity' => 1,
            'replacement_serial_number' => 'SERIAL-C',
        ]);

        $response->assertSessionHas('error');
    }

    /**
     * Test that a serial in return process cannot be used as a replacement.
     */
    public function test_cannot_receive_in_return_process_serial_as_replacement()
    {
        ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SERIAL-D',
            'status' => 'RETURNED',
            'is_in_return_process' => true,
        ]);

        $serialB = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SERIAL-B',
            'status' => 'active',
            'is_in_return_process' => true,
        ]);

        $purchaseReturn = $this->createPurchaseReturn(7000000);
        $detail = $this->createReturnDetail($purchaseReturn->id, 1, 7000000);

        $itemSettlement = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $purchaseReturn->id,
            'purchase_return_detail_id' => $detail->id,
            'product_serial_number_id' => $serialB->id,
            'method' => 'PRODUCT_REPAIR',
            'status' => PurchaseReturnItemSettlement::STATUS_APPROVED_AWAITING_RECEIVE,
        ]);

        $response = $this->post(route('purchase-return-settlements.item.receive', $itemSettlement), [
            'location_id' => $this->location->id,
            'received_quantity' => 1,
            'replacement_serial_number' => 'SERIAL-D',
        ]);

        $response->assertSessionHas('error');
    }

    /**
     * Test that reactivating a returned serial preserves the record (doesn't create new).
     */
    public function test_returned_serial_reactivated_preserves_record()
    {
        $serialA = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SERIAL-A',
            'status' => 'RETURNED',
            'is_in_return_process' => false,
        ]);
        $originalId = $serialA->id;

        $serialB = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SERIAL-B',
            'status' => 'active',
            'is_in_return_process' => true,
        ]);

        $purchaseReturn = $this->createPurchaseReturn(7000000);
        $detail = $this->createReturnDetail($purchaseReturn->id, 1, 7000000);

        $itemSettlement = PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $purchaseReturn->id,
            'purchase_return_detail_id' => $detail->id,
            'product_serial_number_id' => $serialB->id,
            'method' => 'PRODUCT_REPAIR',
            'status' => PurchaseReturnItemSettlement::STATUS_APPROVED_AWAITING_RECEIVE,
        ]);

        $this->post(route('purchase-return-settlements.item.receive', $itemSettlement), [
            'location_id' => $this->location->id,
            'received_quantity' => 1,
            'replacement_serial_number' => 'SERIAL-A',
        ]);

        $this->assertCount(2, ProductSerialNumber::all());
        $serialAReactivated = ProductSerialNumber::where('serial_number', 'SERIAL-A')->first();
        $this->assertEquals($originalId, $serialAReactivated->id);
    }
}
