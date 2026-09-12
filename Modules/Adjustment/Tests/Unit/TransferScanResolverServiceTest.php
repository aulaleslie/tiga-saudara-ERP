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
        $this->assertEquals('serial_rejected', $result['type']);
    }

    public function test_enforces_normal_mode_and_broken_mode_scans()
    {
        $goodSerial = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-GOOD-1',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
        ]);

        $brokenSerial = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-BROKEN-1',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => true,
        ]);

        // Normal mode accepts good
        $resNormalGood = $this->service->resolve($this->setting->id, 'SN-GOOD-1', $this->location->id, false);
        $this->assertEquals('serial_exact', $resNormalGood['type']);

        // Normal mode rejects broken
        $resNormalBroken = $this->service->resolve($this->setting->id, 'SN-BROKEN-1', $this->location->id, false);
        $this->assertEquals('serial_rejected', $resNormalBroken['type']);

        // Broken mode accepts broken
        $resBrokenBroken = $this->service->resolve($this->setting->id, 'SN-BROKEN-1', $this->location->id, true);
        $this->assertEquals('serial_exact', $resBrokenBroken['type']);

        // Broken mode rejects good
        $resBrokenGood = $this->service->resolve($this->setting->id, 'SN-GOOD-1', $this->location->id, true);
        $this->assertEquals('serial_rejected', $resBrokenGood['type']);
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

    public function test_exact_primary_barcode_takes_precedence_over_conversion_and_serial_with_same_value()
    {
        $unit = Unit::create([
            'name' => 'Piece',
            'short_name' => 'PCS',
        ]);
        $this->product->update(['base_unit_id' => $unit->id]);

        // Product A has primary barcode 'SHARED-CODE'
        $productA = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Product A Primary',
            'product_code' => 'PA-001',
            'barcode' => 'SHARED-CODE',
            'stock_managed' => true,
            'product_cost' => 1000,
            'product_price' => 2000,
        ]);
        ProductStock::create([
            'product_id' => $productA->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Product B has a conversion with barcode 'SHARED-CODE'
        $conversion = ProductUnitConversion::create([
            'product_id' => $this->product->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'unit_conversion_name' => 'Box',
            'unit_conversion_code' => 'BOX',
            'conversion_factor' => 12,
            'barcode' => 'SHARED-CODE',
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

        // And a serial exists with serial_number 'SHARED-CODE'
        ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SHARED-CODE',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => 0,
        ]);

        $result = $this->service->resolve($this->setting->id, 'SHARED-CODE', $this->location->id);
        $this->assertEquals('product_exact', $result['type']);
        $this->assertEquals($productA->id, $result['product']['id']);
        $this->assertEquals('product_barcode', $result['product']['resolved_via']);
    }

    public function test_exact_conversion_barcode_takes_precedence_over_serial_with_same_value()
    {
        $unit = Unit::create([
            'name' => 'Piece',
            'short_name' => 'PCS',
        ]);
        $this->product->update(['base_unit_id' => $unit->id]);

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

        // Unit conversion has barcode 'CONV-SERIAL-CODE'
        ProductUnitConversion::create([
            'product_id' => $this->product->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'unit_conversion_name' => 'Box',
            'unit_conversion_code' => 'BOX',
            'conversion_factor' => 10,
            'barcode' => 'CONV-SERIAL-CODE',
        ]);

        // Serial has number 'CONV-SERIAL-CODE'
        ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'CONV-SERIAL-CODE',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => 0,
        ]);

        $result = $this->service->resolve($this->setting->id, 'CONV-SERIAL-CODE', $this->location->id);
        $this->assertEquals('product_exact', $result['type']);
        $this->assertEquals($this->product->id, $result['product']['id']);
        $this->assertEquals('conversion_barcode', $result['product']['resolved_via']);
        $this->assertNotNull($result['product']['conversion']);
    }

    public function test_no_exact_match_returns_none_for_partial_or_unknown_query()
    {
        $result = $this->service->resolve($this->setting->id, 'UNKNOWN-CODE-999', $this->location->id);
        $this->assertEquals('none', $result['type']);
    }

    public function test_exact_barcode_resolution_respects_good_and_broken_conditions()
    {
        // Good-only product has only good stock
        $goodProduct = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Good Only Barcode',
            'product_code' => 'GOB-001',
            'barcode' => 'GOOD-BARCODE-1',
            'stock_managed' => true,
            'product_cost' => 1000,
            'product_price' => 2000,
        ]);
        ProductStock::create([
            'product_id' => $goodProduct->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'quantity_non_tax' => 10,
            'quantity_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Broken-only product has only broken stock
        $brokenProduct = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Broken Only Barcode',
            'product_code' => 'BOB-001',
            'barcode' => 'BROKEN-BARCODE-1',
            'stock_managed' => true,
            'product_cost' => 1000,
            'product_price' => 2000,
        ]);
        ProductStock::create([
            'product_id' => $brokenProduct->id,
            'location_id' => $this->location->id,
            'quantity' => 0,
            'quantity_non_tax' => 0,
            'quantity_tax' => 0,
            'broken_quantity' => 5,
            'broken_quantity_tax' => 2,
            'broken_quantity_non_tax' => 3,
        ]);

        // In good mode (isBrokenMode = false):
        // Good barcode resolves
        $resGoodInGood = $this->service->resolve($this->setting->id, 'GOOD-BARCODE-1', $this->location->id, false);
        $this->assertEquals('product_exact', $resGoodInGood['type']);
        $this->assertEquals($goodProduct->id, $resGoodInGood['product']['id']);

        // Broken-only barcode does not resolve in good mode
        $resBrokenInGood = $this->service->resolve($this->setting->id, 'BROKEN-BARCODE-1', $this->location->id, false);
        $this->assertEquals('none', $resBrokenInGood['type']);

        // In broken mode (isBrokenMode = true):
        // Broken barcode resolves
        $resBrokenInBroken = $this->service->resolve($this->setting->id, 'BROKEN-BARCODE-1', $this->location->id, true);
        $this->assertEquals('product_exact', $resBrokenInBroken['type']);
        $this->assertEquals($brokenProduct->id, $resBrokenInBroken['product']['id']);

        // Good barcode does not resolve in broken mode
        $resGoodInBroken = $this->service->resolve($this->setting->id, 'GOOD-BARCODE-1', $this->location->id, true);
        $this->assertEquals('none', $resGoodInBroken['type']);
    }
}
