<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Unit;

use Tests\TestCase;
use Modules\Adjustment\Services\TransferScanResolverService;
use Modules\Adjustment\Services\TransferStockVisibility;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Setting\Entities\Location;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;

class TransferScanCollisionResolverTest extends TestCase
{
    use RefreshDatabase;

    private TransferScanResolverService $service;
    private Setting $setting;
    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TransferScanResolverService();

        Permission::firstOrCreate(['name' => TransferStockVisibility::PERMISSION, 'guard_name' => 'web']);

        $currency = Currency::create([
            'currency_name' => 'Rupiah Test',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Collision Test Co',
            'company_email' => 'test@collision.com',
            'company_phone' => '0800112233',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@collision.com',
            'footer_text' => 'Footer',
            'company_address' => 'Bandung',
        ]);

        $this->location = Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Origin Warehouse',
        ]);

        session(['setting_id' => $this->setting->id]);
    }

    private function createStock(int $productId, int $tax = 10, int $nonTax = 0, int $brokenTax = 0, int $brokenNonTax = 0): ProductStock
    {
        return ProductStock::create([
            'product_id' => $productId,
            'location_id' => $this->location->id,
            'quantity' => $tax + $nonTax,
            'quantity_tax' => $tax,
            'quantity_non_tax' => $nonTax,
            'broken_quantity' => $brokenTax + $brokenNonTax,
            'broken_quantity_tax' => $brokenTax,
            'broken_quantity_non_tax' => $brokenNonTax,
        ]);
    }

    /** @test */
    public function task_1_3_duplicate_product_barcodes_return_ambiguous_outcome()
    {
        // Temporarily disable unique index on products.barcode for SQLite/MySQL to simulate legacy or collision fixtures
        \Illuminate\Support\Facades\Schema::table('products', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->dropUnique(['barcode']);
        });

        $productA = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Product Alpha',
            'product_code' => 'PA-001',
            'barcode' => 'DUP-BARCODE-1',
            'stock_managed' => true,
            'product_cost' => 1000,
            'product_price' => 2000,
        ]);
        $this->createStock($productA->id, 10, 0);

        $productB = Product::create([
            'setting_id' => $this->setting->id,
            'product_name' => 'Product Beta',
            'product_code' => 'PB-002',
            'barcode' => 'DUP-BARCODE-1',
            'stock_managed' => true,
            'product_cost' => 1500,
            'product_price' => 2500,
        ]);
        $this->createStock($productB->id, 5, 0);

        $result = $this->service->resolve($this->setting->id, 'DUP-BARCODE-1', $this->location->id, false);

        // Under new contract (tasks 3.1/1.3), duplicate barcodes MUST return status => ambiguous
        $this->assertEquals('ambiguous', $result['status'] ?? $result['type']);
        $this->assertCount(2, $result['candidates']);
    }

    /** @test */
    public function task_1_3_cross_type_product_and_serial_collision_returns_ambiguous_outcome()
    {
        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'PCS']);

        $productA = Product::create([
            'setting_id' => $this->setting->id,
            'base_unit_id' => $unit->id,
            'product_name' => 'Product Primary',
            'product_code' => 'PP-001',
            'barcode' => 'COLLIDE-CODE-1',
            'stock_managed' => true,
            'product_cost' => 1000,
            'product_price' => 2000,
        ]);
        $this->createStock($productA->id, 10, 0);

        $productB = Product::create([
            'setting_id' => $this->setting->id,
            'base_unit_id' => $unit->id,
            'product_name' => 'Product Serialized',
            'product_code' => 'PS-002',
            'serial_number_required' => true,
            'stock_managed' => true,
            'product_cost' => 5000,
            'product_price' => 8000,
        ]);
        $this->createStock($productB->id, 5, 0);

        $serial = ProductSerialNumber::create([
            'product_id' => $productB->id,
            'location_id' => $this->location->id,
            'serial_number' => 'COLLIDE-CODE-1',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
        ]);

        $result = $this->service->resolve($this->setting->id, 'COLLIDE-CODE-1', $this->location->id, false);

        $this->assertEquals('ambiguous', $result['status'] ?? $result['type']);
        $this->assertCount(2, $result['candidates']);
    }

    /** @test */
    public function task_1_3_cross_type_conversion_and_serial_collision_returns_ambiguous_outcome()
    {
        $unit = Unit::create(['name' => 'Pcs', 'short_name' => 'PCS']);
        $boxUnit = Unit::create(['name' => 'Box', 'short_name' => 'BOX']);

        $productA = Product::create([
            'setting_id' => $this->setting->id,
            'base_unit_id' => $unit->id,
            'product_name' => 'Product Conversion',
            'product_code' => 'PC-001',
            'stock_managed' => true,
            'product_cost' => 1000,
            'product_price' => 2000,
        ]);

        ProductUnitConversion::create([
            'product_id' => $productA->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $unit->id,
            'conversion_factor' => 10,
            'barcode' => 'CONV-SER-COLLIDE',
        ]);
        $this->createStock($productA->id, 20, 0);

        $productB = Product::create([
            'setting_id' => $this->setting->id,
            'base_unit_id' => $unit->id,
            'product_name' => 'Product Serial Only',
            'product_code' => 'PSO-002',
            'serial_number_required' => true,
            'stock_managed' => true,
            'product_cost' => 5000,
            'product_price' => 8000,
        ]);
        $this->createStock($productB->id, 5, 0);

        ProductSerialNumber::create([
            'product_id' => $productB->id,
            'location_id' => $this->location->id,
            'serial_number' => 'CONV-SER-COLLIDE',
            'status' => ProductSerialNumber::STATUS_ACTIVE,
            'is_broken' => false,
        ]);

        $result = $this->service->resolve($this->setting->id, 'CONV-SER-COLLIDE', $this->location->id, false);

        $this->assertEquals('ambiguous', $result['status'] ?? $result['type']);
        $this->assertCount(2, $result['candidates']);
    }
}
