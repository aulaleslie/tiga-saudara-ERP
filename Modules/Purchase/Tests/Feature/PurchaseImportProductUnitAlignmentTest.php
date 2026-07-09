<?php

namespace Modules\Purchase\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Setting\Entities\Unit;
use Modules\Purchase\Services\PurchaseImportService;
use Tests\TestCase;

class PurchaseImportProductUnitAlignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_import_creates_product_with_canonical_unit_fields()
    {
        $unit = Unit::create(['name' => 'Pieces', 'short_name' => 'PCS']);
        $service = app(PurchaseImportService::class);
        $settingId = 1; // dummy setting id
        
        $product = $service->findOrCreateProduct('Imported Product', 'PCS', $settingId);

        $this->assertEquals($unit->id, $product->base_unit_id);
        $this->assertEquals($unit->id, $product->unit_id);
        $this->assertEquals('PCS', $product->product_unit);
    }
}
