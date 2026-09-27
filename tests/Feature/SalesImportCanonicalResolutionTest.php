<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Sale\Services\SalesImportService;
use Modules\Product\Exceptions\AmbiguousProductResolutionException;
use Tests\TestCase;

class SalesImportCanonicalResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected SalesImportService $service;
    protected int $settingId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SalesImportService::class);
    }

    public function test_formatting_and_marker_variants_reuse_one_canonical_product()
    {
        $product1 = $this->service->findOrCreateProduct('  *   SAMSUNG SSD 1TB   ', 'PCS', $this->settingId);
        
        $product2 = $this->service->findOrCreateProduct('samsung ssd 1tb TP', 'PCS', $this->settingId);
        
        $this->assertEquals($product1->id, $product2->id);
        $this->assertEquals(1, Product::count());
    }

    public function test_ambiguous_legacy_duplicate_throws_exception()
    {
        Product::create([
            'product_name' => 'Legacy Keyboard',
            'product_code' => 'SKU-S1',
            'setting_id' => $this->settingId,
            'is_purchased' => 1, 'is_sold' => 1,
            'product_cost' => 0, 'product_price' => 0, 'product_quantity' => 0,
        ]);
        Product::create([
            'product_name' => 'LEGACY KEYBOARD',
            'product_code' => 'SKU-S2',
            'setting_id' => $this->settingId,
            'is_purchased' => 1, 'is_sold' => 1,
            'product_cost' => 0, 'product_price' => 0, 'product_quantity' => 0,
        ]);

        $this->expectException(AmbiguousProductResolutionException::class);
        $this->service->findOrCreateProduct('Legacy Keyboard', 'PCS', $this->settingId);
    }

    public function test_no_duplicate_product_is_created()
    {
        $this->service->findOrCreateProduct('Sales Item', 'PCS', $this->settingId);
        $this->service->findOrCreateProduct('sales item', 'PCS', $this->settingId);

        $this->assertEquals(1, Product::where('canonical_name', 'sales item')->count());
    }

    public function test_preload_does_not_silently_ignore_legacy_ambiguity()
    {
        $p1 = Product::create([
            'product_name' => 'sales x',
            'canonical_name' => 'sales x',
            'product_code' => 'SKU-S60',
            'setting_id' => $this->settingId,
            'is_purchased' => 1, 'is_sold' => 1,
            'product_cost' => 0, 'product_price' => 0, 'product_quantity' => 0,
        ]);
        $p2 = Product::create([
            'product_name' => 'Sales   X',
            'canonical_name' => null,
            'product_code' => 'SKU-S231',
            'setting_id' => $this->settingId,
            'is_purchased' => 1, 'is_sold' => 1,
            'product_cost' => 0, 'product_price' => 0, 'product_quantity' => 0,
        ]);

        $row = (object)['raw_json' => ['produk' => 'sales x']];
        $rows = collect([$row]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('preloadProductsForBatch');
        $method->setAccessible(true);
        $method->invoke($this->service, $rows);

        $property = $reflection->getProperty('productsCache');
        $property->setAccessible(true);
        $cache = $property->getValue($this->service);
        
        $this->assertArrayNotHasKey('sales x', $cache);

        $this->expectException(AmbiguousProductResolutionException::class);
        $this->service->findOrCreateProduct('sales x', 'PCS', $this->settingId);
    }
}
