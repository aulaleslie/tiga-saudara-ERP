<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProductStockSerialConversionPoolValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'products.convert_existing_stock_to_serialized', 'guard_name' => 'web']);
    }

    public function test_aggregates_pools_across_multiple_settings()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('products.convert_existing_stock_to_serialized');

        $setting1 = Setting::create([
            'company_name' => 'Pusat',
            'company_email' => 'pusat@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'pusat@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Pusat',
            'company_address' => 'Address Pusat',
        ]);
        $setting2 = Setting::create([
            'company_name' => 'Cabang 2',
            'company_email' => 'cabang2@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'cabang2@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Cabang 2',
            'company_address' => 'Address Cabang 2',
        ]);

        $loc1 = Location::create(['name' => 'Gudang Pusat', 'setting_id' => $setting1->id]);
        $loc2 = Location::create(['name' => 'Gudang Cabang 2', 'setting_id' => $setting2->id]);

        $product = Product::create([
            'product_name' => 'Laptop ASUS ROG',
            'product_code' => 'ROG-001',
            'setting_id' => $setting1->id,
            'product_cost' => 0,
            'product_price' => 0,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc1->id,
            'quantity' => 10,
            'quantity_non_tax' => 7,
            'quantity_tax' => 3,
            'broken_quantity' => 2,
            'broken_quantity_non_tax' => 2,
            'broken_quantity_tax' => 0,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc2->id,
            'quantity' => 5,
            'quantity_non_tax' => 0,
            'quantity_tax' => 5,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $response = $this->actingAs($user)->get(route('products.convert-to-serialized.show', ['product' => $product->id]));

        $response->assertStatus(200);
        $response->assertViewHas('pools', function ($pools) use ($setting1, $setting2) {
            return isset($pools[$setting1->id]) &&
                   $pools[$setting1->id]['pools']['normal_non_tax'] === 7 &&
                   $pools[$setting1->id]['pools']['normal_tax'] === 3 &&
                   $pools[$setting1->id]['pools']['broken_non_tax'] === 2 &&
                   isset($pools[$setting2->id]) &&
                   $pools[$setting2->id]['pools']['normal_tax'] === 5;
        });
    }

    public function test_validates_scan_rejects_existing_serial_in_database()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('products.convert_existing_stock_to_serialized');

        $setting = Setting::create([
            'company_name' => 'Pusat',
            'company_email' => 'pusat@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'pusat@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Pusat',
            'company_address' => 'Address Pusat',
        ]);
        $loc = Location::create(['name' => 'Gudang Pusat', 'setting_id' => $setting->id]);

        $otherProduct = Product::create([
            'product_name' => 'Other Product',
            'product_code' => 'OTH-001',
            'setting_id' => $setting->id,
            'product_cost' => 0,
            'product_price' => 0,
            'stock_managed' => true,
            'serial_number_required' => true,
            'is_active' => true,
        ]);

        ProductSerialNumber::create([
            'product_id' => $otherProduct->id,
            'location_id' => $loc->id,
            'serial_number' => 'SN-GLOBAL-DUPLICATE',
            'status' => 'SOLD',
        ]);

        $response = $this->actingAs($user)->postJson(route('products.convert-to-serialized.validate-scan'), [
            'serial_number' => 'SN-GLOBAL-DUPLICATE',
            'session_serials' => [],
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'valid' => false,
            'message' => 'Nomor seri ini sudah terdaftar di sistem database.',
        ]);
    }

    public function test_eligibility_allows_conversion_when_only_draft_transfer_exists()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('products.convert_existing_stock_to_serialized');

        $setting = Setting::create([
            'company_name' => 'Transfer Setting',
            'company_email' => 'transfer@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'transfer@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Transfer',
            'company_address' => 'Transfer Address',
        ]);
        $loc1 = Location::create(['name' => 'Gudang A', 'setting_id' => $setting->id]);
        $loc2 = Location::create(['name' => 'Gudang B', 'setting_id' => $setting->id]);

        $product = Product::create([
            'product_name' => 'Product In Transfer',
            'product_code' => 'PIT-001',
            'setting_id' => $setting->id,
            'product_cost' => 0,
            'product_price' => 0,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc1->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $transfer = \Modules\Adjustment\Entities\Transfer::create([
            'origin_location_id' => $loc1->id,
            'destination_location_id' => $loc2->id,
            'created_by' => $user->id,
            'status' => \Modules\Adjustment\Entities\Transfer::STATUS_DRAFT,
        ]);

        \Modules\Adjustment\Entities\TransferProduct::create([
            'transfer_id' => $transfer->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_id' => 1,
        ]);

        $service = app(\Modules\Product\Services\SerialConversionEligibilityService::class);
        $result = $service->checkEligibility($product);

        $this->assertTrue($result->isEligible);
        $this->assertEmpty($result->blockingReasons);
    }

    public function test_eligibility_blocks_conversion_when_active_non_draft_transfer_exists()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('products.convert_existing_stock_to_serialized');

        $setting = Setting::create([
            'company_name' => 'Transfer Setting',
            'company_email' => 'transfer@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'transfer@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Transfer',
            'company_address' => 'Transfer Address',
        ]);
        $loc1 = Location::create(['name' => 'Gudang A', 'setting_id' => $setting->id]);
        $loc2 = Location::create(['name' => 'Gudang B', 'setting_id' => $setting->id]);

        $product = Product::create([
            'product_name' => 'Product In Transfer',
            'product_code' => 'PIT-001',
            'setting_id' => $setting->id,
            'product_cost' => 0,
            'product_price' => 0,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc1->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $transfer = \Modules\Adjustment\Entities\Transfer::create([
            'origin_location_id' => $loc1->id,
            'destination_location_id' => $loc2->id,
            'created_by' => $user->id,
            'status' => \Modules\Adjustment\Entities\Transfer::STATUS_PENDING,
        ]);

        \Modules\Adjustment\Entities\TransferProduct::create([
            'transfer_id' => $transfer->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_id' => 1,
        ]);

        $service = app(\Modules\Product\Services\SerialConversionEligibilityService::class);
        $result = $service->checkEligibility($product);

        $this->assertFalse($result->isEligible);
        $this->assertContains('Terdapat dokumen Transfer Stok yang sedang aktif/berjalan untuk produk ini.', $result->blockingReasons);
    }

    public function test_eligibility_allows_conversion_when_only_draft_purchase_return_exists()
    {
        $user = User::factory()->create();

        $setting = Setting::create([
            'company_name' => 'PR Setting',
            'company_email' => 'pr@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'pr@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'PR',
            'company_address' => 'PR Address',
        ]);
        $loc = Location::create(['name' => 'Gudang PR', 'setting_id' => $setting->id]);

        $product = Product::create([
            'product_name' => 'Product In PR',
            'product_code' => 'PIPR-001',
            'setting_id' => $setting->id,
            'product_cost' => 0,
            'product_price' => 0,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc->id,
            'quantity' => 5,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $supplier = \Modules\People\Entities\Supplier::create([
            'setting_id' => $setting->id,
            'supplier_name' => 'PR Supplier',
            'supplier_email' => 'pr_supplier@example.com',
            'supplier_phone' => '08123456789',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        $purchaseReturn = \Modules\PurchasesReturn\Entities\PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PRTN-001',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => \Modules\PurchasesReturn\Entities\PurchaseReturn::STATUS_DRAFT,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);

        \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'price' => 500,
            'unit_price' => 500,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $service = app(\Modules\Product\Services\SerialConversionEligibilityService::class);
        $result = $service->checkEligibility($product);

        $this->assertTrue($result->isEligible);
        $this->assertEmpty($result->blockingReasons);
    }

    public function test_eligibility_blocks_conversion_when_active_non_draft_purchase_return_exists()
    {
        $user = User::factory()->create();

        $setting = Setting::create([
            'company_name' => 'PR Setting',
            'company_email' => 'pr@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'pr@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'PR',
            'company_address' => 'PR Address',
        ]);
        $loc = Location::create(['name' => 'Gudang PR', 'setting_id' => $setting->id]);

        $product = Product::create([
            'product_name' => 'Product In PR',
            'product_code' => 'PIPR-001',
            'setting_id' => $setting->id,
            'product_cost' => 0,
            'product_price' => 0,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc->id,
            'quantity' => 5,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $supplier = \Modules\People\Entities\Supplier::create([
            'setting_id' => $setting->id,
            'supplier_name' => 'PR Supplier',
            'supplier_email' => 'pr_supplier@example.com',
            'supplier_phone' => '08123456789',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        $purchaseReturn = \Modules\PurchasesReturn\Entities\PurchaseReturn::create([
            'date' => now(),
            'reference' => 'PRTN-001',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => \Modules\PurchasesReturn\Entities\PurchaseReturn::STATUS_PENDING_APPROVAL,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);

        \Modules\PurchasesReturn\Entities\PurchaseReturnDetail::create([
            'purchase_return_id' => $purchaseReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'price' => 500,
            'unit_price' => 500,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $service = app(\Modules\Product\Services\SerialConversionEligibilityService::class);
        $result = $service->checkEligibility($product);

        $this->assertFalse($result->isEligible);
        $this->assertContains('Terdapat dokumen Retur Pembelian yang belum selesai untuk produk ini.', $result->blockingReasons);
    }

    public function test_eligibility_allows_conversion_when_only_draft_adjustment_exists()
    {
        $user = User::factory()->create();

        $setting = Setting::create([
            'company_name' => 'Adj Setting',
            'company_email' => 'adj@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'adj@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Adj',
            'company_address' => 'Adj Address',
        ]);
        $loc = Location::create(['name' => 'Gudang Adj', 'setting_id' => $setting->id]);

        $product = Product::create([
            'product_name' => 'Product In Adj',
            'product_code' => 'PIA-001',
            'setting_id' => $setting->id,
            'product_cost' => 0,
            'product_price' => 0,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc->id,
            'quantity' => 5,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $adj = \Modules\Adjustment\Entities\Adjustment::create([
            'location_id' => $loc->id,
            'status' => 'draft',
            'date' => now(),
        ]);

        \Modules\Adjustment\Entities\AdjustedProduct::create([
            'adjustment_id' => $adj->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'type' => 'add',
        ]);

        $service = app(\Modules\Product\Services\SerialConversionEligibilityService::class);
        $result = $service->checkEligibility($product);

        $this->assertTrue($result->isEligible);
        $this->assertEmpty($result->blockingReasons);
    }

    public function test_eligibility_blocks_conversion_when_active_non_draft_adjustment_exists()
    {
        $user = User::factory()->create();

        $setting = Setting::create([
            'company_name' => 'Adj Setting',
            'company_email' => 'adj@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'adj@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Adj',
            'company_address' => 'Adj Address',
        ]);
        $loc = Location::create(['name' => 'Gudang Adj', 'setting_id' => $setting->id]);

        $product = Product::create([
            'product_name' => 'Product In Adj',
            'product_code' => 'PIA-001',
            'setting_id' => $setting->id,
            'product_cost' => 0,
            'product_price' => 0,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc->id,
            'quantity' => 5,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $adj = \Modules\Adjustment\Entities\Adjustment::create([
            'location_id' => $loc->id,
            'status' => 'pending',
            'date' => now(),
        ]);

        \Modules\Adjustment\Entities\AdjustedProduct::create([
            'adjustment_id' => $adj->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'type' => 'add',
        ]);

        $service = app(\Modules\Product\Services\SerialConversionEligibilityService::class);
        $result = $service->checkEligibility($product);

        $this->assertFalse($result->isEligible);
        $this->assertContains('Terdapat dokumen Penyesuaian Stok (Adjustment) berstatus PENDING/DRAFT untuk produk ini.', $result->blockingReasons);
    }

    public function test_eligibility_allows_conversion_when_only_draft_sales_return_exists()
    {
        $user = User::factory()->create();

        $setting = Setting::create([
            'company_name' => 'SR Setting',
            'company_email' => 'sr@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'sr@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'SR',
            'company_address' => 'SR Address',
        ]);
        $loc = Location::create(['name' => 'Gudang SR', 'setting_id' => $setting->id]);

        $product = Product::create([
            'product_name' => 'Product In Sales Return',
            'product_code' => 'PISR-001',
            'setting_id' => $setting->id,
            'product_cost' => 0,
            'product_price' => 0,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc->id,
            'quantity' => 5,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $customer = \Modules\People\Entities\Customer::create([
            'customer_name' => 'Pelanggan Test',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '08123456789',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        $saleReturn = \Modules\SalesReturn\Entities\SaleReturn::create([
            'date' => now(),
            'reference' => 'SLR-001',
            'setting_id' => $setting->id,
            'location_id' => $loc->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'status' => 'Draft',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);

        \Modules\SalesReturn\Entities\SaleReturnDetail::create([
            'sale_return_id' => $saleReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'price' => 1000,
            'quantity' => 1,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $service = app(\Modules\Product\Services\SerialConversionEligibilityService::class);
        $result = $service->checkEligibility($product);

        $this->assertTrue($result->isEligible);
        $this->assertEmpty($result->blockingReasons);
    }

    public function test_eligibility_blocks_conversion_when_active_non_draft_sales_return_exists()
    {
        $user = User::factory()->create();

        $setting = Setting::create([
            'company_name' => 'SR Setting',
            'company_email' => 'sr@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'sr@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'SR',
            'company_address' => 'SR Address',
        ]);
        $loc = Location::create(['name' => 'Gudang SR', 'setting_id' => $setting->id]);

        $product = Product::create([
            'product_name' => 'Product In Sales Return',
            'product_code' => 'PISR-001',
            'setting_id' => $setting->id,
            'product_cost' => 0,
            'product_price' => 0,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc->id,
            'quantity' => 5,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $customer = \Modules\People\Entities\Customer::create([
            'customer_name' => 'Pelanggan Test',
            'customer_email' => 'customer@example.com',
            'customer_phone' => '08123456789',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        $saleReturn = \Modules\SalesReturn\Entities\SaleReturn::create([
            'date' => now(),
            'reference' => 'SLR-001',
            'setting_id' => $setting->id,
            'location_id' => $loc->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'status' => 'Awaiting Settlement',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);

        \Modules\SalesReturn\Entities\SaleReturnDetail::create([
            'sale_return_id' => $saleReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'price' => 1000,
            'quantity' => 1,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        \Modules\SalesReturn\Entities\SaleReturnItemSettlement::create([
            'sale_return_id' => $saleReturn->id,
            'sale_return_detail_id' => 1,
            'nominal' => 1000,
            'status' => \Modules\SalesReturn\Entities\SaleReturnItemSettlement::STATUS_SUBMITTED,
        ]);

        $service = app(\Modules\Product\Services\SerialConversionEligibilityService::class);
        $result = $service->checkEligibility($product);

        $this->assertFalse($result->isEligible);
        $this->assertContains('Terdapat dokumen Retur Penjualan yang belum selesai untuk produk ini.', $result->blockingReasons);
    }

    public function test_eligibility_blocks_conversion_when_sale_return_has_draft_settlement_items()
    {
        $user = User::factory()->create();

        $setting = Setting::create([
            'company_name' => 'SR Draft Settlement Setting',
            'company_email' => 'srs@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'srs@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'SR Settlement',
            'company_address' => 'SR Settlement Address',
        ]);
        $loc = Location::create(['name' => 'Gudang SR Settlement', 'setting_id' => $setting->id]);

        $product = Product::create([
            'product_name' => 'Product In SR Draft Settlement',
            'product_code' => 'PISRS-001',
            'setting_id' => $setting->id,
            'product_cost' => 0,
            'product_price' => 0,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc->id,
            'quantity' => 5,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $customer = \Modules\People\Entities\Customer::create([
            'customer_name' => 'Pelanggan SR Draft Settlement',
            'customer_email' => 'srs@example.com',
            'customer_phone' => '08123456789',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        $saleReturn = \Modules\SalesReturn\Entities\SaleReturn::create([
            'date' => now(),
            'reference' => 'SLR-DRAFT-SETTLEMENT-001',
            'setting_id' => $setting->id,
            'location_id' => $loc->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
        ]);

        \Modules\SalesReturn\Entities\SaleReturnDetail::create([
            'sale_return_id' => $saleReturn->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'price' => 1000,
            'quantity' => 1,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        \Modules\SalesReturn\Entities\SaleReturnItemSettlement::create([
            'sale_return_id' => $saleReturn->id,
            'sale_return_detail_id' => 1,
            'nominal' => 1000,
            'status' => \Modules\SalesReturn\Entities\SaleReturnItemSettlement::STATUS_DRAFT,
        ]);

        $service = app(\Modules\Product\Services\SerialConversionEligibilityService::class);
        $result = $service->checkEligibility($product);

        $this->assertFalse($result->isEligible);
        $this->assertContains('Terdapat dokumen Retur Penjualan yang belum selesai untuk produk ini.', $result->blockingReasons);
    }

    public function test_eligibility_allows_conversion_when_historical_sale_has_returned_partially_status()
    {
        $user = User::factory()->create();

        $setting = Setting::create([
            'company_name' => 'Historical Setting',
            'company_email' => 'hist@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'hist@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Historical',
            'company_address' => 'Historical Address',
        ]);
        $loc = Location::create(['name' => 'Gudang Hist', 'setting_id' => $setting->id]);

        $product = Product::create([
            'product_name' => 'Product Historical Sale',
            'product_code' => 'PHS-001',
            'setting_id' => $setting->id,
            'product_cost' => 0,
            'product_price' => 0,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc->id,
            'quantity' => 5,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $customer = \Modules\People\Entities\Customer::create([
            'customer_name' => 'Pelanggan Hist',
            'customer_email' => 'hist@example.com',
            'customer_phone' => '08123456789',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        // Create completed historical sale with RETURNED PARTIALLY status
        $sale = \Modules\Sale\Entities\Sale::create([
            'date' => now()->subDays(10),
            'reference' => 'SL-HIST-001',
            'setting_id' => $setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 1000,
            'due_amount' => 0,
            'status' => \Modules\Sale\Entities\Sale::STATUS_RETURNED_PARTIALLY,
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
        ]);

        \Modules\Sale\Entities\SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'price' => 500,
            'unit_price' => 500,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $service = app(\Modules\Product\Services\SerialConversionEligibilityService::class);
        $result = $service->checkEligibility($product);

        $this->assertTrue($result->isEligible);
        $this->assertEmpty($result->blockingReasons);
    }

    public function test_eligibility_blocks_conversion_when_pending_received_note_exists()
    {
        Permission::firstOrCreate(['name' => 'purchases.receive.access', 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->givePermissionTo('purchases.receive.access');
        $this->actingAs($user);

        $setting = Setting::create([
            'company_name' => 'RN Setting',
            'company_email' => 'rn@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'rn@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'RN',
            'company_address' => 'RN Address',
        ]);
        $loc = Location::create(['name' => 'Gudang RN', 'setting_id' => $setting->id]);

        $product = Product::create([
            'product_name' => 'Product In RN',
            'product_code' => 'PIRN-001',
            'setting_id' => $setting->id,
            'product_cost' => 0,
            'product_price' => 0,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc->id,
            'quantity' => 5,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $supplier = \Modules\People\Entities\Supplier::create([
            'setting_id' => $setting->id,
            'supplier_name' => 'Supplier Test',
            'supplier_email' => 'supplier@example.com',
            'supplier_phone' => '08123456789',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        $purchase = \Modules\Purchase\Entities\Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PRCH-001',
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->supplier_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => 'Pending',
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);

        \Modules\Purchase\Entities\PurchaseDetail::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'price' => 500,
            'unit_price' => 500,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        \Modules\Purchase\Entities\ReceivedNote::create([
            'po_id' => $purchase->id,
            'external_delivery_number' => 'DEL-SJ-001',
            'date' => now(),
            'location_id' => $loc->id,
            'status' => \Modules\Purchase\Entities\ReceivedNote::STATUS_PENDING,
        ]);

        $service = app(\Modules\Product\Services\SerialConversionEligibilityService::class);
        $result = $service->checkEligibility($product);

        $this->assertFalse($result->isEligible);
        $this->assertContains('Terdapat dokumen Penerimaan Barang (Received Note) berstatus PENDING untuk produk ini.', $result->blockingReasons);
        $this->assertEquals('DEL-SJ-001', $result->structuredBlockers[0]['document_number']);
        $this->assertEquals(route('purchases.receivings', $purchase->id), $result->structuredBlockers[0]['url']);
    }

    public function test_eligibility_blocks_conversion_when_active_consignment_receiving_exists()
    {
        $user = User::factory()->create();

        $setting = Setting::create([
            'company_name' => 'CS Setting',
            'company_email' => 'cs@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'cs@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'CS',
            'company_address' => 'CS Address',
        ]);
        $loc = Location::create(['name' => 'Gudang CS', 'setting_id' => $setting->id]);

        $product = Product::create([
            'product_name' => 'Product In CS',
            'product_code' => 'PICS-001',
            'setting_id' => $setting->id,
            'product_cost' => 0,
            'product_price' => 0,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc->id,
            'quantity' => 5,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $csSupplier = \Modules\People\Entities\Supplier::create([
            'setting_id' => $setting->id,
            'supplier_name' => 'CS Supplier Test',
            'supplier_email' => 'cs_supplier@example.com',
            'supplier_phone' => '08123456789',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        $receival = \Modules\Consignment\Entities\ConsignmentReceival::create([
            'setting_id' => $setting->id,
            'supplier_id' => $csSupplier->id,
            'reference' => 'CRCV-001',
            'receival_number' => 'CRCV-001',
            'date' => now(),
            'status' => 'APPROVED',
        ]);

        $consignment = \Modules\Consignment\Entities\ConsignmentReceiving::create([
            'consignment_receival_id' => $receival->id,
            'setting_id' => $setting->id,
            'location_id' => $loc->id,
            'receiving_number' => 'CSR-001',
            'date' => now(),
            'status' => \Modules\Consignment\Entities\ConsignmentReceiving::STATUS_PENDING,
        ]);

        $receivalLine = \Modules\Consignment\Entities\ConsignmentReceivalLine::create([
            'consignment_receival_id' => $receival->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'unit_cost' => 500,
            'unit_dpp' => 500,
            'unit_tax_amount' => 0,
            'subtotal_cost' => 1000,
            'subtotal_dpp' => 1000,
            'subtotal_tax_amount' => 0,
            'total_cost' => 1000,
            'total_dpp' => 1000,
            'total_tax_amount' => 0,
        ]);

        \Modules\Consignment\Entities\ConsignmentReceivingDetail::create([
            'consignment_receiving_id' => $consignment->id,
            'consignment_receival_line_id' => $receivalLine->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'quantity_received' => 2,
            'unit_cost' => 500,
            'unit_dpp' => 500,
            'unit_tax_amount' => 0,
            'total_cost' => 1000,
            'total_dpp' => 1000,
            'total_tax_amount' => 0,
        ]);

        $service = app(\Modules\Product\Services\SerialConversionEligibilityService::class);
        $result = $service->checkEligibility($product);

        $this->assertFalse($result->isEligible);
        $this->assertContains('Terdapat dokumen Penerimaan Konsinyasi berstatus PENDING untuk produk ini.', $result->blockingReasons);
    }

    public function test_eligibility_allows_conversion_when_only_draft_sale_exists()
    {
        $user = User::factory()->create();

        $setting = Setting::create([
            'company_name' => 'Draft Sale Setting',
            'company_email' => 'ds@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'ds@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Draft Sale',
            'company_address' => 'Draft Sale Address',
        ]);
        $loc = Location::create(['name' => 'Gudang DS', 'setting_id' => $setting->id]);

        $product = Product::create([
            'product_name' => 'Product Draft Sale',
            'product_code' => 'PDS-001',
            'setting_id' => $setting->id,
            'product_cost' => 0,
            'product_price' => 0,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc->id,
            'quantity' => 5,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $customer = \Modules\People\Entities\Customer::create([
            'customer_name' => 'Pelanggan DS',
            'customer_email' => 'ds@example.com',
            'customer_phone' => '08123456789',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        $sale = \Modules\Sale\Entities\Sale::create([
            'date' => now(),
            'reference' => 'SL-DRAFT-001',
            'setting_id' => $setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => \Modules\Sale\Entities\Sale::STATUS_DRAFTED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);

        \Modules\Sale\Entities\SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $service = app(\Modules\Product\Services\SerialConversionEligibilityService::class);
        $result = $service->checkEligibility($product);

        $this->assertTrue($result->isEligible);
        $this->assertEmpty($result->blockingReasons);
    }

    public function test_eligibility_blocks_conversion_when_active_non_draft_sale_exists()
    {
        $user = User::factory()->create();

        $setting = Setting::create([
            'company_name' => 'Draft Sale Setting',
            'company_email' => 'ds@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'ds@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Draft Sale',
            'company_address' => 'Draft Sale Address',
        ]);
        $loc = Location::create(['name' => 'Gudang DS', 'setting_id' => $setting->id]);

        $product = Product::create([
            'product_name' => 'Product Draft Sale',
            'product_code' => 'PDS-001',
            'setting_id' => $setting->id,
            'product_cost' => 0,
            'product_price' => 0,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc->id,
            'quantity' => 5,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $customer = \Modules\People\Entities\Customer::create([
            'customer_name' => 'Pelanggan DS',
            'customer_email' => 'ds@example.com',
            'customer_phone' => '08123456789',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        $sale = \Modules\Sale\Entities\Sale::create([
            'date' => now(),
            'reference' => 'SL-DRAFT-001',
            'setting_id' => $setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => \Modules\Sale\Entities\Sale::STATUS_WAITING_APPROVAL,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);

        \Modules\Sale\Entities\SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $service = app(\Modules\Product\Services\SerialConversionEligibilityService::class);
        $result = $service->checkEligibility($product);

        $this->assertFalse($result->isEligible);
        $this->assertContains('Terdapat dokumen Penjualan atau Pengiriman yang belum selesai untuk produk ini.', $result->blockingReasons);
    }

    public function test_structured_blockers_returned_with_full_details_and_authorization_links()
    {
        Permission::firstOrCreate(['name' => 'sales.show', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'sales.access', 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->givePermissionTo('sales.show');
        $this->actingAs($user);

        $setting = Setting::create([
            'company_name' => 'Structured Test Setting',
            'company_email' => 'struct@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'struct@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Struct',
            'company_address' => 'Struct Address',
        ]);
        $loc = Location::create(['name' => 'Gudang Struct', 'setting_id' => $setting->id]);

        $product = Product::create([
            'product_name' => 'Product Multi Sale',
            'product_code' => 'PMS-001',
            'setting_id' => $setting->id,
            'product_cost' => 0,
            'product_price' => 0,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $customer = \Modules\People\Entities\Customer::create([
            'customer_name' => 'Pelanggan Multi',
            'customer_email' => 'multi@example.com',
            'customer_phone' => '08123456789',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        $sale1 = \Modules\Sale\Entities\Sale::create([
            'date' => now(),
            'reference' => 'SL-2026-00101',
            'setting_id' => $setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => \Modules\Sale\Entities\Sale::STATUS_WAITING_APPROVAL,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);
        \Modules\Sale\Entities\SaleDetails::create([
            'sale_id' => $sale1->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $sale2 = \Modules\Sale\Entities\Sale::create([
            'date' => now(),
            'reference' => 'SL-2026-00102',
            'setting_id' => $setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'status' => \Modules\Sale\Entities\Sale::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);
        \Modules\Sale\Entities\SaleDetails::create([
            'sale_id' => $sale2->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 2000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $service = app(\Modules\Product\Services\SerialConversionEligibilityService::class);
        $result = $service->checkEligibility($product);

        $this->assertFalse($result->isEligible);
        $this->assertCount(2, $result->structuredBlockers);

        $blocker1 = collect($result->structuredBlockers)->firstWhere('document_number', 'SL-2026-00101');
        $this->assertNotNull($blocker1);
        $this->assertEquals('sale', $blocker1['type']);
        $this->assertEquals((int) $sale1->id, (int) $blocker1['document_id']);
        $this->assertEquals('WAITING_APPROVAL', $blocker1['status']);
        $this->assertEquals('Dokumen penjualan masih aktif dan dapat mengubah stok produk.', $blocker1['reason']);
        $this->assertEquals(route('sales.show', $sale1->id), $blocker1['url']);
        $this->assertTrue($blocker1['can_view']);

        // Verify requesting the generated URL with sales.show returns 200 OK
        session(['setting_id' => $setting->id]);
        $urlResponse = $this->get($blocker1['url']);
        $urlResponse->assertOk();

        // Test user with ONLY list/access permission (sales.access) but NOT sales.show
        $listOnlyUser = User::factory()->create();
        $listOnlyUser->givePermissionTo('sales.access');
        $this->actingAs($listOnlyUser);

        $listOnlyResult = $service->checkEligibility($product);
        $listOnlyBlocker = collect($listOnlyResult->structuredBlockers)->firstWhere('document_number', 'SL-2026-00101');
        $this->assertFalse($listOnlyBlocker['can_view']);
        $this->assertNull($listOnlyBlocker['url']);

        // Unauthorized user test
        $unauth = User::factory()->create();
        $this->actingAs($unauth);

        $unauthResult = $service->checkEligibility($product);
        $this->assertFalse($unauthResult->structuredBlockers[0]['can_view']);
        $this->assertNull($unauthResult->structuredBlockers[0]['url']);
    }

    public function test_conversion_view_renders_structured_blockers_with_links_and_new_tab()
    {
        Permission::firstOrCreate(['name' => 'products.convert_existing_stock_to_serialized', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'sales.show', 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->givePermissionTo('products.convert_existing_stock_to_serialized');
        $user->givePermissionTo('sales.show');

        $setting = Setting::create([
            'company_name' => 'View Render Setting',
            'company_email' => 'view@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'view@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'View',
            'company_address' => 'View Address',
        ]);
        $loc = Location::create(['name' => 'Gudang View', 'setting_id' => $setting->id]);

        $product = Product::create([
            'product_name' => 'Product View Test',
            'product_code' => 'PVT-001',
            'setting_id' => $setting->id,
            'product_cost' => 0,
            'product_price' => 0,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $customer = \Modules\People\Entities\Customer::create([
            'customer_name' => 'Pelanggan View',
            'customer_email' => 'pv@example.com',
            'customer_phone' => '08123456789',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        $sale = \Modules\Sale\Entities\Sale::create([
            'date' => now(),
            'reference' => 'SL-VIEW-0099',
            'setting_id' => $setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => \Modules\Sale\Entities\Sale::STATUS_WAITING_APPROVAL,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);
        \Modules\Sale\Entities\SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $response = $this->actingAs($user)->get(route('products.convert-to-serialized.show', ['product_id' => $product->id]));

        $response->assertStatus(200);
        $response->assertSee('Dokumen Aktif Memblokir Konversi:');
        $response->assertSee('SL-VIEW-0099');
        $response->assertSee('target="_blank"', false);
        $response->assertSee('rel="noopener noreferrer"', false);
        $response->assertSee(route('sales.show', $sale->id));

        // Test without sales.show permission
        $unauthUser = User::factory()->create();
        $unauthUser->givePermissionTo('products.convert_existing_stock_to_serialized');

        $unauthResponse = $this->actingAs($unauthUser)->get(route('products.convert-to-serialized.show', ['product_id' => $product->id]));
        $unauthResponse->assertStatus(200);
        $unauthResponse->assertSee('SL-VIEW-0099');
        $unauthResponse->assertSee('Anda tidak memiliki izin untuk membuka dokumen ini.');
        $unauthResponse->assertDontSee(route('sales.show', $sale->id));
    }

    public function test_combining_multiple_documents_with_non_document_reasons_preserves_all_non_document_reasons()
    {
        Permission::firstOrCreate(['name' => 'products.convert_existing_stock_to_serialized', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'sales.show', 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->givePermissionTo('products.convert_existing_stock_to_serialized');
        $user->givePermissionTo('sales.show');

        $setting = Setting::create([
            'company_name' => 'Combined Reason Setting',
            'company_email' => 'combined@example.com',
            'company_phone' => '08123456789',
            'notification_email' => 'combined@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Combined',
            'company_address' => 'Combined Address',
        ]);
        $loc = Location::create(['name' => 'Gudang Combined', 'setting_id' => $setting->id]);

        // Product with non-document blockers: inactive AND fractional stock
        $product = Product::create([
            'product_name' => 'Inactive Fractional Product',
            'product_code' => 'IFP-001',
            'setting_id' => $setting->id,
            'product_cost' => 0,
            'product_price' => 0,
            'stock_managed' => true,
            'serial_number_required' => false,
            'is_active' => false, // Non-document reason 1: inactive
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $loc->id,
            'quantity' => 2.5, // Non-document reason 2: fractional stock
            'quantity_non_tax' => 2.5,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $customer = \Modules\People\Entities\Customer::create([
            'customer_name' => 'Pelanggan Combined',
            'customer_email' => 'comb@example.com',
            'customer_phone' => '08123456789',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
        ]);

        // Create 2 active sales for this product (both non-draft)
        $sale1 = \Modules\Sale\Entities\Sale::create([
            'date' => now(),
            'reference' => 'SL-COMB-001',
            'setting_id' => $setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => \Modules\Sale\Entities\Sale::STATUS_WAITING_APPROVAL,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);
        \Modules\Sale\Entities\SaleDetails::create([
            'sale_id' => $sale1->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 1,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 1000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $sale2 = \Modules\Sale\Entities\Sale::create([
            'date' => now(),
            'reference' => 'SL-COMB-002',
            'setting_id' => $setting->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 2000,
            'paid_amount' => 0,
            'due_amount' => 2000,
            'status' => \Modules\Sale\Entities\Sale::STATUS_DISPATCHED_PARTIALLY,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
        ]);
        \Modules\Sale\Entities\SaleDetails::create([
            'sale_id' => $sale2->id,
            'product_id' => $product->id,
            'product_name' => $product->product_name,
            'product_code' => $product->product_code,
            'quantity' => 2,
            'price' => 1000,
            'unit_price' => 1000,
            'sub_total' => 2000,
            'product_discount_amount' => 0,
            'product_discount_type' => 'fixed',
            'product_tax_amount' => 0,
        ]);

        $service = app(\Modules\Product\Services\SerialConversionEligibilityService::class);
        $result = $service->checkEligibility($product);

        $this->assertFalse($result->isEligible);
        $this->assertCount(2, $result->structuredBlockers);
        $this->assertContains('Produk tidak aktif.', $result->nonDocumentReasons);
        $this->assertContains('Jumlah stok harus berupa bilangan bulat utuh (ditemukan pecahan pada quantity).', $result->nonDocumentReasons);

        // Blade view rendering check
        $response = $this->actingAs($user)->get(route('products.convert-to-serialized.show', ['product_id' => $product->id]));
        $response->assertStatus(200);
        $response->assertSee('SL-COMB-001');
        $response->assertSee('SL-COMB-002');
        $response->assertSee('Produk tidak aktif.');
        $response->assertSee('Jumlah stok harus berupa bilangan bulat utuh (ditemukan pecahan pada quantity).');
    }
}
