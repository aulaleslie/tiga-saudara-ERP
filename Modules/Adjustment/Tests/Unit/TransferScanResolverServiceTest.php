<?php

namespace Modules\Adjustment\Tests\Unit;

use Tests\TestCase;
use Modules\Adjustment\Services\TransferScanResolverService;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductUnitConversion;
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
        $result = $this->service->resolve($this->location->id, '', $this->product->id);
        $this->assertEquals('none', $result['type']);
    }

    public function test_resolves_product_barcode()
    {
        // Create a product with a barcode
        $product = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Test Product With Barcode',
            'product_code' => 'TP-002',
            'product_barcode' => 'BARCODE-001',
            'serial_number_required' => 0,
            'product_cost' => 50000,
            'product_price' => 100000,
            'stock_managed' => true,
        ]);

        $result = $this->service->resolve($this->location->id, 'BARCODE-001', $this->product->id);
        $this->assertNotNull($result);
        $this->assertEquals('product', $result['type']);
    }

    public function test_resolves_conversion_barcode()
    {
        // Create a unit conversion with barcode
        $conversion = ProductUnitConversion::create([
            'product_id' => $this->product->id,
            'unit_conversion_name' => 'Box',
            'unit_conversion_code' => 'BOX',
            'unit_conversion_factor' => 12,
            'barcode' => 'CONVERSION-001',
        ]);

        $result = $this->service->resolve($this->location->id, 'CONVERSION-001', $this->product->id);
        $this->assertNotNull($result);
        $this->assertEquals('conversion', $result['type']);
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

        $result = $this->service->resolve($this->location->id, 'SN-12345', $this->product->id);
        $this->assertNotNull($result);
        $this->assertEquals('serial', $result['type']);
    }

    public function test_rejects_non_active_serial()
    {
        // Create an inactive serial number
        $serial = ProductSerialNumber::create([
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'serial_number' => 'SN-INACTIVE',
            'status' => ProductSerialNumber::STATUS_INACTIVE,
            'is_broken' => 0,
            'tax_id' => null,
            'dispatch_detail_id' => null,
            'is_in_return_process' => 0,
        ]);

        $result = $this->service->resolve($this->location->id, 'SN-INACTIVE', $this->product->id);
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
        $result = $this->service->resolve($this->location->id, 'SN-OTHER-LOC', $this->product->id);
        $this->assertEquals('none', $result['type']);
    }
}

