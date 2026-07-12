<?php

namespace Modules\Adjustment\Tests\Unit;

use Tests\TestCase;
use Modules\Adjustment\Services\TransferScanResolverService;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Setting\Entities\Location;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Setting;

class TransferScanResolverServiceTest extends TestCase
{
    use RefreshDatabase;

    private TransferScanResolverService $service;
    private $setting;
    private $location;
    private $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TransferScanResolverService();
        
        // Setup test data
        $currency = Currency::create([
            'currency_name' => 'Rupiah Test',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Company',
            'company_email' => 'test@example.com',
            'company_phone' => '08001234567',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Test Footer',
            'company_address' => 'Test Address',
        ]);

        $this->location = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Test Warehouse',
        ]);

        $this->product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Product',
            'product_code' => 'TP-001',
            'serial_number_required' => 1,
            'product_cost' => 50000,
            'product_price' => 100000,
            'stock_managed' => true,
        ]);
    }

    public function test_resolves_none_for_empty_query()
    {
        $result = $this->service->resolve($this->setting->id, '', $this->location->id);
        $this->assertEquals('none', $result['type']);
    }

    public function test_resolves_product_barcode()
    {
        // Create a product with a barcode
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Product With Barcode',
            'product_code' => 'TP-002',
            'barcode' => 'BARCODE-001',
            'serial_number_required' => 0,
            'product_cost' => 50000,
            'product_price' => 100000,
            'stock_managed' => true,
        ]);

        // Add positive stock at origin location
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity_non_tax' => 10,
            'quantity_tax' => 5,
            'quantity' => 15,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $result = $this->service->resolve($this->setting->id, 'BARCODE-001', $this->location->id);
        $this->assertNotNull($result);
        $this->assertEquals('product_exact', $result['type']);
    }

    public function test_resolves_conversion_barcode()
    {
        // Create base unit first
        $unit = Unit::create([
            'name' => 'Piece',
            'short_name' => 'PCS',
        ]);

        // Set base unit on product
        $this->product->update(['base_unit_id' => $unit->id]);

        // Create a unit conversion with barcode and valid unit_id/base_unit_id
        $conversion = ProductUnitConversion::create([
            'product_id' => $this->product->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'unit_conversion_name' => 'Box',
            'unit_conversion_code' => 'BOX',
            'conversion_factor' => 12,
            'barcode' => 'CONVERSION-001',
        ]);

        // Add positive stock at origin location
        ProductStock::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'quantity_non_tax' => 10,
            'quantity_tax' => 5,
            'quantity' => 15,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        $result = $this->service->resolve($this->setting->id, 'CONVERSION-001', $this->location->id);
        $this->assertNotNull($result);
        $this->assertEquals('product_exact', $result['type']);
        $this->assertNotNull($result['product']['conversion']);
    }

    public function test_resolves_serial_number()
    {
        // Create a serial number
        $serial = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-12345',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => 0,
            'tax_id' => null,
            'dispatch_detail_id' => null,
            'is_in_return_process' => 0,
        ]);

        $result = $this->service->resolve($this->setting->id, 'SN-12345', $this->location->id);
        $this->assertNotNull($result);
        $this->assertEquals('serial_exact', $result['type']);
        $this->assertNotNull($result['serial']);
        $this->assertEquals($serial->id, $result['serial']['id']);
    }

    public function test_rejects_non_active_serial()
    {
        // Create a non-active serial number
        $serial = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-SOLD',
            'status' => ProductSerialNumber::STATUS_SOLD,
            'is_broken' => 0,
            'tax_id' => null,
            'dispatch_detail_id' => null,
            'is_in_return_process' => 0,
        ]);

        $result = $this->service->resolve($this->setting->id, 'SN-SOLD', $this->location->id);
        $this->assertEquals('none', $result['type']);
    }

    public function test_rejects_serial_not_at_location()
    {
        // Create another location
        $otherLocation = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Other Warehouse',
        ]);

        // Create a serial at a different location
        $serial = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $otherLocation->id,
            'serial_number' => 'SN-OTHER-LOC',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => 0,
            'tax_id' => null,
            'dispatch_detail_id' => null,
            'is_in_return_process' => 0,
        ]);

        // Try to resolve at original location
        $result = $this->service->resolve($this->setting->id, 'SN-OTHER-LOC', $this->location->id);
        $this->assertEquals('none', $result['type']);
    }

    /** @test */
    public function it_rejects_invalid_origin_location()
    {
        // Create a location from a different tenant
        $otherLocation = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Other Setting Location',
        ]);

        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Product',
            'product_code' => 'TST',
            'stock_managed' => true,
            'product_price' => 100,
            'product_cost' => 50,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $otherLocation->id,
            'quantity_non_tax' => 5,
            'quantity_tax' => 0,
            'quantity' => 5,
            'broken_quantity' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
        ]);

        // Try to resolve with location from different tenant
        $wrongSettingId = $this->setting->id + 999;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid origin location for current tenant");

        $this->service->resolve($wrongSettingId, 'BARCODE123', $otherLocation->id);
    }

    /** @test */
    public function it_rejects_serial_from_different_tenant_product()
    {
        // Create a product in a different tenant
        $differentSetting = Setting::create([
            'company_name' => 'Different Company',
            'company_email' => 'different@example.com',
            'company_phone' => '08007654321',
            'default_currency_id' => $this->setting->default_currency_id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@different.com',
            'footer_text' => 'Different Footer',
            'company_address' => 'Different Address',
        ]);

        $differentLocation = Location::create([
            'setting_id' => $differentSetting->id,
            'name' => 'Different Tenant Location',
        ]);

        $differentProduct = Product::create([
            'setting_id' => $differentSetting->id,
            'product_name' => 'Other Tenant Product',
            'product_code' => 'OTP',
            'serial_number_required' => true,
            'stock_managed' => true,
            'product_price' => 100,
            'product_cost' => 50,
        ]);

        // Create serial for product in different tenant but at location in original tenant
        // This should not happen in normal operation, but we test the guard
        $serial = ProductSerialNumber::create([
            'product_id' => $differentProduct->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-DIFFERENT-TENANT',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => 0,
            'tax_id' => null,
            'dispatch_detail_id' => null,
            'is_in_return_process' => 0,
        ]);

        // Try to resolve - should return none because product belongs to different tenant
        $result = $this->service->resolve($this->setting->id, 'SN-DIFFERENT-TENANT', $this->location->id);
        $this->assertEquals('none', $result['type']);
    }
}

