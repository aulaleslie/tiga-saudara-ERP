<?php

namespace Modules\Sale\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Unit;
use Modules\Sale\Entities\SalesImportBatch;
use Modules\Sale\Services\SalesImportService;
use Tests\TestCase;

class SalesImportProductUnitAlignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_import_creates_product_with_canonical_unit_fields()
    {
        $unit = Unit::create(['name' => 'Pieces', 'short_name' => 'PCS']);
        $service = app(SalesImportService::class);
        $settingId = 1; // dummy setting id
        
        $product = $service->findOrCreateProduct('Imported Product', 'PCS', $settingId);

        $this->assertEquals($unit->id, $product->base_unit_id);
        $this->assertEquals($unit->id, $product->unit_id);
        $this->assertEquals('PCS', $product->product_unit);
    }
}
