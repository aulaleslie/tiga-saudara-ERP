<?php

namespace Tests\Feature\SalesReturn;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\SalesReturn\Entities\SaleReturn;
use Modules\SalesReturn\Entities\SaleReturnDetail;
use Modules\SalesReturn\Entities\SaleReturnItemSettlement;
use Modules\Setting\Entities\Location;
use Tests\TestCase;

class SettlementWorkflowTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    public function test_settlement_options_are_correct()
    {
        $this->actingAsAdmin();
        $saleReturn = $this->createSaleReturn();

        Livewire::test('sales-return.sale-return-settlement-form', ['saleReturnId' => $saleReturn->id])
            ->assertSee('Perbaikan/Pergantian Produk')
            ->assertSee('Pengembalian Tunai')
            ->assertSee('Tidak dapat diproses')
            ->assertDontSee('Ubah Nota Penjualan')
            ->assertDontSee('Simpan Sebagai Kredit');
    }

    public function test_submit_repair_replacement_non_serial()
    {
        $this->actingAsAdmin();
        $saleReturn = $this->createSaleReturn();
        // Manual Location creation
        $setting = \Modules\Setting\Entities\Setting::first(); 
        $location = Location::create([
            'setting_id' => $setting->id,
            'name' => 'Test Location',
        ]);

        Livewire::test('sales-return.sale-return-settlement-form', ['saleReturnId' => $saleReturn->id])
            ->set('settlementLines.0.method', SaleReturnDetail::METHOD_PRODUCT_REPAIR)
            ->set('settlementLines.0.location_id', $location->id)
            ->call('submitLine', 0)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sale_return_item_settlements', [
            'sale_return_id' => $saleReturn->id,
            'method' => SaleReturnDetail::METHOD_PRODUCT_REPAIR,
            'location_id' => $location->id,
            'status' => SaleReturnItemSettlement::STATUS_SUBMITTED,
        ]);
    }

    public function test_submit_repair_replacement_serial()
    {
        $this->actingAsAdmin();
        $saleReturn = $this->createSaleReturnWithSerial();
        $product = $saleReturn->saleReturnDetails->first()->product;
        
        // Manual Serial creation
        $setting = \Modules\Setting\Entities\Setting::first();
        $location = Location::create([
            'setting_id' => $setting->id,
            'name' => 'Test Location 2',
        ]);

        ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => 'NEW-SN-001',
            'status' => 'Active',
            'location_id' => $location->id,
        ]);

        Livewire::test('sales-return.sale-return-settlement-form', ['saleReturnId' => $saleReturn->id])
            ->set('settlementLines.0.method', SaleReturnDetail::METHOD_PRODUCT_REPAIR)
            ->set('settlementLines.0.new_serial_number', 'NEW-SN-001')
            ->call('submitLine', 0)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sale_return_item_settlements', [
            'sale_return_id' => $saleReturn->id,
            'method' => SaleReturnDetail::METHOD_PRODUCT_REPAIR,
            'new_serial_number' => 'NEW-SN-001',
            'status' => SaleReturnItemSettlement::STATUS_SUBMITTED,
        ]);
    }

    public function test_submit_cash_refund_with_proof()
    {
        $this->actingAsAdmin();
        $saleReturn = $this->createSaleReturn();
        
        $file = UploadedFile::fake()->image('proof.jpg');

        Livewire::test('sales-return.sale-return-settlement-form', ['saleReturnId' => $saleReturn->id])
            ->set('settlementLines.0.method', SaleReturnDetail::METHOD_CASH_REFUND)
            ->set('settlementLines.0.proof_file', $file)
            ->call('submitLine', 0)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sale_return_item_settlements', [
            'sale_return_id' => $saleReturn->id,
            'method' => SaleReturnDetail::METHOD_CASH_REFUND,
            'status' => SaleReturnItemSettlement::STATUS_SUBMITTED,
        ]);
    }

    public function test_submit_unprocessed_with_reason()
    {
        $this->actingAsAdmin();
        $saleReturn = $this->createSaleReturn();

        Livewire::test('sales-return.sale-return-settlement-form', ['saleReturnId' => $saleReturn->id])
            ->set('settlementLines.0.method', SaleReturnDetail::METHOD_UNPROCESSED)
            ->set('settlementLines.0.notes', 'Cannot process due to heavy damage')
            ->call('submitLine', 0)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sale_return_item_settlements', [
            'sale_return_id' => $saleReturn->id,
            'method' => SaleReturnDetail::METHOD_UNPROCESSED,
            'notes' => 'CANNOT PROCESS DUE TO HEAVY DAMAGE',
            'status' => SaleReturnItemSettlement::STATUS_SUBMITTED,
        ]);
    }

    // Helpers
    protected function createSaleReturn()
    {
        // Create dependencies
        $setting = \Modules\Setting\Entities\Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'company@test.com',
            'company_phone' => '1234567890',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@test.com',
            'footer_text' => 'Footer Text',
            'company_address' => 'Company Address',
        ]);

        $customer = \Modules\People\Entities\Customer::create([
            'customer_name' => 'Test Customer',
            'customer_email' => 'test@test.com',
            'customer_phone' => '1234567890',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
        ]);

        $category = \Modules\Product\Entities\Category::create([
            'category_code' => 'TEST_CAT',
            'category_name' => 'Test Category',
            'created_by' => 1,
            'setting_id' => $setting->id,
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Test Product',
            'product_code' => 'TP-001',
            'product_price' => 10000,
            'product_cost' => 8000,
            'product_quantity' => 10,
            'product_stock_alert' => 1,
            'product_unit' => 'pcs',
            'setting_id' => $setting->id,
        ]);
        
        $saleReturn = SaleReturn::create([
             'date' => now(),
             'reference' => 'RT-001',
             'customer_id' => $customer->id,
             'customer_name' => 'Test Customer',
             'tax_percentage' => 0,
             'tax_amount' => 0,
             'discount_percentage' => 0,
             'discount_amount' => 0,
             'shipping_amount' => 0,
             'total_amount' => 10000,
             'paid_amount' => 0,
             'due_amount' => 10000,
             'status' => 'Pending',
             'payment_status' => 'Unpaid',
             'payment_method' => 'Cash',
             'note' => '',
             'setting_id' => $setting->id,
        ]);

        $detail = SaleReturnDetail::create([
            'sale_return_id' => $saleReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'unit_price' => 10000,
            'sub_total' => 10000,
            'price' => 10000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'serial_number_ids' => [],
        ]);
        return $saleReturn->refresh();
    }

    protected function createSaleReturnWithSerial()
    {
        $setting = \Modules\Setting\Entities\Setting::create([
            'company_name' => 'Serial Company',
            'company_email' => 'serial@test.com',
            'company_phone' => '1234567890',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@test.com',
            'footer_text' => 'Footer Text',
            'company_address' => 'Company Address',
        ]);

        $customer = \Modules\People\Entities\Customer::create([
            'customer_name' => 'Test Customer',
            'customer_email' => 'test_serial@test.com',
            'customer_phone' => '1234567890',
            'city' => 'Test City',
            'country' => 'Test Country',
            'address' => 'Test Address',
        ]);

        $category = \Modules\Product\Entities\Category::create([
            'category_code' => 'SERIAL_CAT',
            'category_name' => 'Serial Category',
            'created_by' => 1,
            'setting_id' => $setting->id,
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'product_name' => 'Serial Product',
            'product_code' => 'SP-001-' . uniqid(),
            'product_price' => 10000,
            'product_cost' => 8000,
            'product_quantity' => 10,
            'product_stock_alert' => 1,
            'product_unit' => 'pcs',
            'serial_number_required' => 1,
            'setting_id' => $setting->id,
        ]);
        
        $location = Location::create([
            'setting_id' => $setting->id,
            'name' => 'Test Location',
        ]);

        $sn = ProductSerialNumber::create([
            'product_id' => $product->id, 
            'serial_number' => 'OLD-SN-' . uniqid(), 
            'status' => 'Active',
            'location_id' => $location->id,
        ]);
        
        $saleReturn = SaleReturn::create([
             'date' => now(),
             'reference' => 'RT-002',
             'customer_id' => $customer->id,
             'customer_name' => 'Test Customer',
             'tax_percentage' => 0,
             'tax_amount' => 0,
             'discount_percentage' => 0,
             'discount_amount' => 0,
             'shipping_amount' => 0,
             'total_amount' => 10000,
             'paid_amount' => 0,
             'due_amount' => 10000,
             'status' => 'Pending',
             'payment_status' => 'Unpaid',
             'payment_method' => 'Cash',
             'note' => '',
             'setting_id' => $setting->id,
        ]);

        $detail = SaleReturnDetail::create([
            'sale_return_id' => $saleReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'unit_price' => 10000,
            'sub_total' => 10000,
            'price' => 10000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'serial_number_ids' => [$sn->id],
        ]);
        return $saleReturn->refresh();
    }

    protected function actingAsAdmin()
    {
        $role = \Spatie\Permission\Models\Role::create(['name' => 'Super Admin', 'guard_name' => 'web']);
        $user = \App\Models\User::factory()->create();
        $user->assignRole($role); 
        $this->actingAs($user);
    }
}
