<?php

namespace Modules\PurchasesReturn\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\People\Entities\Supplier;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\PurchasesReturn\Entities\PurchaseReturn;
use Modules\PurchasesReturn\Entities\PurchaseReturnDetail;
use Modules\PurchasesReturn\Entities\PurchaseReturnItemSettlement;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchasesReturnGranularSettlementTest extends TestCase
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

    /** @test */
    public function it_can_load_settlement_lines_for_serial_and_non_serial_products()
    {
        // 1. Create a serial product and a non-serial product
        $serialProduct = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Serial Product',
            'product_code' => 'P001',
            'product_cost' => 10,
            'product_price' => 20,
            'serial_number_required' => true,
        ]);
        
        $nonSerialProduct = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Non-Serial Product',
            'product_code' => 'P002',
            'product_cost' => 10,
            'product_price' => 20,
            'serial_number_required' => false,
        ]);

        // 2. Create SNs
        $sn1 = ProductSerialNumber::create(['product_id' => $serialProduct->id, 'serial_number' => 'SN001', 'location_id' => $this->location->id]);
        $sn2 = ProductSerialNumber::create(['product_id' => $serialProduct->id, 'serial_number' => 'SN002', 'location_id' => $this->location->id]);

        // 3. Create Purchase Return
        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PRRN-TEST-001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'total_amount' => 3000,
            'paid_amount' => 0,
            'due_amount' => 3000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'approved',
        ]);

        // Detail for Serial Product (2 units)
        PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $serialProduct->id,
            'product_name' => $serialProduct->product_name,
            'product_code' => $serialProduct->product_code,
            'quantity' => 2,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 2000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'serial_number_ids' => [$sn1->id, $sn2->id],
            'location_id' => $this->location->id,
        ]);

        // Detail for Non-Serial Product (1 unit)
        PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $nonSerialProduct->id,
            'product_name' => $nonSerialProduct->product_name,
            'product_code' => $nonSerialProduct->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
        ]);

        Livewire::test(\App\Livewire\PurchaseReturn\PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->assertSet('purchaseReturnId', $purchaseReturn->id)
            ->assertCount('settlementLines', 3) // 2 for serials, 1 for non-serial
            ->assertSet('settlementLines.0.serial_number', 'SN001')
            ->assertSet('settlementLines.1.serial_number', 'SN002')
            ->assertSet('settlementLines.2.product_name', 'NON-SERIAL PRODUCT');
    }

    /** @test */
    public function it_persists_granular_settlement_information()
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Product',
            'product_code' => 'P999',
            'product_cost' => 10,
            'product_price' => 20,
            'serial_number_required' => false
        ]);
        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PRRN-TEST-002',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'approved',
        ]);

        $detail = PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
        ]);

        Livewire::test(\App\Livewire\PurchaseReturn\PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->set('settlementLines.0.method', PurchaseReturnDetail::METHOD_PRODUCT_REPAIR)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('purchase-returns.show', $purchaseReturn->id));

        $this->assertDatabaseHas('purchase_return_item_settlements', [
            'purchase_return_id' => $purchaseReturn->id,
            'purchase_return_detail_id' => $detail->id,
            'method' => PurchaseReturnDetail::METHOD_PRODUCT_REPAIR,
        ]);

        $this->assertDatabaseHas('purchase_return_settlements', [
            'purchase_return_id' => $purchaseReturn->id,
            'method' => 'MIXED',
            'status' => 'PENDING',
        ]);
    }

    /** @test */
    public function it_requires_method_for_each_settlement_line()
    {
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Product',
            'product_code' => 'P888',
            'product_cost' => 10,
            'product_price' => 20,
            'serial_number_required' => false
        ]);
        $purchaseReturn = PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PRRN-TEST-003',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'setting_id' => $this->setting->id,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'approved',
        ]);

        PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'location_id' => $this->location->id,
        ]);

        Livewire::test(\App\Livewire\PurchaseReturn\PurchaseReturnSettlementForm::class, ['purchaseReturnId' => $purchaseReturn->id])
            ->set('settlementLines.0.method', '') // Empty
            ->call('submit')
            ->assertHasErrors(['settlementLines.0.method' => 'required']);
    }
}
