<?php

namespace Modules\PurchasesReturn\Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use App\Models\User;

class PurchaseReturnSettlementPhase3Test extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $setting;
    protected $location;
    protected $targetLocation;
    protected $product;
    protected $serialProduct;

    protected function setUp(): void
    {
        parent::setUp();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        \Spatie\Permission\Models\Permission::findOrCreate('purchaseReturnSettlements.receive');

        $this->user = User::factory()->create(['is_active' => 1]);
        $this->user->givePermissionTo('purchaseReturnSettlements.receive');
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
            'name' => 'Source Warehouse',
            'setting_id' => $this->setting->id,
        ]);

        $this->targetLocation = Location::create([
            'name' => 'Target Warehouse',
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
            'product_name' => 'Normal Product',
            'product_code' => 'P001',
            'product_unit' => 'pcs',
            'product_price' => 1000,
            'product_cost' => 800,
            'category_id' => $category->id,
            'setting_id' => $this->setting->id,
            'product_quantity' => 10,
        ]);

        $this->serialProduct = Product::create([
            'product_name' => 'Serial Product',
            'product_code' => 'SP001',
            'product_unit' => 'pcs',
            'product_price' => 2000,
            'product_cost' => 1500,
            'category_id' => $category->id,
            'setting_id' => $this->setting->id,
            'product_quantity' => 1,
            'serial_number_required' => true,
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

    private function createPurchaseReturn($product, $qty = 1)
    {
        return PurchaseReturn::create([
            'supplier_id' => 1,
            'supplier_name' => 'Test Supplier',
            'total_amount' => $product->product_price * $qty,
            'paid_amount' => 0,
            'due_amount' => $product->product_price * $qty,
            'tax_amount' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'payment_method' => 'Cash',
            'payment_status' => 'Unpaid',
            'reference' => 'PR-' . uniqid(),
            'status' => 'Pending',
            'location_id' => $this->location->id,
            'setting_id' => $this->setting->id,
            'date' => now(),
        ]);
    }

    private function createSettlementItem($purchaseReturn, $product, $method, $qty = 1, $serial = null)
    {
        $detail = PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => $qty,
            'price' => $product->product_price,
            'unit_price' => $product->product_price,
            'sub_total' => $product->product_price * $qty,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
        ]);

        return PurchaseReturnItemSettlement::create([
            'purchase_return_id' => $purchaseReturn->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => $method,
            'nominal' => $detail->sub_total,
            'status' => PurchaseReturnItemSettlement::STATUS_APPROVED_AWAITING_RECEIVE,
            'product_serial_number_id' => $serial?->id,
        ]);
    }

    /** @test */
    public function test_product_repair_serial_same_serial_restores_status()
    {
        $sn = ProductSerialNumber::create([
            'product_id' => $this->serialProduct->id,
            'serial_number' => 'SN-SAME-001',
            'status' => 'DISPATCHED',
            'is_in_return_process' => true,
            'location_id' => $this->location->id,
        ]);

        $pr = $this->createPurchaseReturn($this->serialProduct);
        $item = $this->createSettlementItem($pr, $this->serialProduct, 'PRODUCT_REPAIR', 1, $sn);

        $response = $this->post(route('purchase-return-settlements.item.receive', $item->id), [
            'location_id' => $this->targetLocation->id,
            'received_quantity' => 1,
            'replacement_serial_number' => 'SN-SAME-001',
        ]);

        $response->assertSessionHas('success');
        $sn->refresh();
        $this->assertEquals('AVAILABLE', $sn->status);
        $this->assertFalse((bool)$sn->is_in_return_process);
        $this->assertEquals($this->targetLocation->id, $sn->location_id);
    }

    /** @test */
    public function test_product_repair_serial_different_serial_marks_old_as_returned()
    {
        $sn = ProductSerialNumber::create([
            'product_id' => $this->serialProduct->id,
            'serial_number' => 'SN-OLD-999',
            'status' => 'DISPATCHED',
            'is_in_return_process' => true,
            'location_id' => $this->location->id,
        ]);

        $pr = $this->createPurchaseReturn($this->serialProduct);
        $item = $this->createSettlementItem($pr, $this->serialProduct, 'PRODUCT_REPAIR', 1, $sn);

        $newSerialText = 'SN-NEW-111';

        $this->post(route('purchase-return-settlements.item.receive', $item->id), [
            'location_id' => $this->targetLocation->id,
            'received_quantity' => 1,
            'replacement_serial_number' => $newSerialText,
        ]);

        $sn->refresh();
        $this->assertEquals('RETURNED', $sn->status);
        $this->assertFalse((bool)$sn->is_in_return_process);

        $newSn = ProductSerialNumber::where('serial_number', $newSerialText)->first();
        $this->assertNotNull($newSn);
        $this->assertEquals('AVAILABLE', $newSn->status);
        $this->assertEquals($this->targetLocation->id, $newSn->location_id);
    }

    /** @test */
    public function test_product_repair_non_serial_moves_stock_to_new_location()
    {
        $pr = $this->createPurchaseReturn($this->product, 2);
        $item = $this->createSettlementItem($pr, $this->product, 'PRODUCT_REPAIR', 2);

        $this->post(route('purchase-return-settlements.item.receive', $item->id), [
            'location_id' => $this->targetLocation->id,
            'received_quantity' => 2,
        ]);

        $sourceStock = ProductStock::where('product_id', $this->product->id)->where('location_id', $this->location->id)->first();
        $targetStock = ProductStock::where('product_id', $this->product->id)->where('location_id', $this->targetLocation->id)->first();

        $this->assertEquals(8, $sourceStock->quantity);
        $this->assertEquals(2, $targetStock->quantity);
        
        $this->assertDatabaseHas('transactions', [
            'type' => 'RETURN_REPAIR',
            'quantity' => -2,
            'location_id' => $this->location->id,
        ]);
        
        $this->assertDatabaseHas('transactions', [
            'type' => 'RETURN_REPAIR',
            'quantity' => 2,
            'location_id' => $this->targetLocation->id,
        ]);
    }

    /** @test */
    public function test_broken_stock_moves_qty_to_broken_quantity()
    {
        $pr = $this->createPurchaseReturn($this->product, 3);
        $item = $this->createSettlementItem($pr, $this->product, 'BROKEN_STOCK', 3);

        $this->post(route('purchase-return-settlements.item.receive', $item->id), [
            'location_id' => $this->targetLocation->id,
            'received_quantity' => 3,
        ]);

        $sourceStock = ProductStock::where('product_id', $this->product->id)->where('location_id', $this->location->id)->first();
        $targetStock = ProductStock::where('product_id', $this->product->id)->where('location_id', $this->targetLocation->id)->first();

        $this->assertEquals(7, $sourceStock->quantity);
        $this->assertEquals(3, $targetStock->broken_quantity);
        
        $this->assertDatabaseHas('transactions', [
            'type' => 'RETURN_BROKEN',
            'quantity' => -3,
            'location_id' => $this->location->id,
        ]);

        $this->assertDatabaseHas('transactions', [
            'type' => 'RETURN_BROKEN',
            'quantity' => 3,
            'location_id' => $this->targetLocation->id,
        ]);
    }

    /** @test */
    public function test_broken_stock_enforces_fixed_quantity()
    {
        $pr = $this->createPurchaseReturn($this->product, 5);
        $item = $this->createSettlementItem($pr, $this->product, 'BROKEN_STOCK', 5);

        $response = $this->post(route('purchase-return-settlements.item.receive', $item->id), [
            'location_id' => $this->targetLocation->id,
            'received_quantity' => 3, // Wrong quantity
        ]);

        $response->assertSessionHasErrors('received_quantity');
    }

    /** @test */
    public function test_returned_serials_excluded_from_serial_search()
    {
        ProductSerialNumber::create([
            'product_id' => $this->serialProduct->id,
            'serial_number' => 'SN-RETURNED-001',
            'status' => 'RETURNED',
            'location_id' => $this->location->id,
        ]);

        ProductSerialNumber::create([
            'product_id' => $this->serialProduct->id,
            'serial_number' => 'SN-ACTIVE-001',
            'status' => 'AVAILABLE',
            'location_id' => $this->location->id,
        ]);

        $component = \Livewire\Livewire::test(\App\Livewire\AutoComplete\SerialNumberLoader::class, [
            'product_id' => $this->serialProduct->id
        ]);

        $component->set('isFocused', true);
        $component->set('query', 'SN-');
        
        $this->assertCount(1, $component->get('search_results'));
        $this->assertEquals('SN-ACTIVE-001', $component->get('search_results')[0]['serial_number']);
    }
}
