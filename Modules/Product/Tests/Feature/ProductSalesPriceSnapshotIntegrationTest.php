<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductImportBatch;
use Modules\Product\Entities\ProductImportRow;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Jobs\ProcessSalesPriceSnapshotBatch;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ProductSalesPriceSnapshotIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private $user;
    private $location;
    private $tigaNusa;
    private $topIt;
    private $perdana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tigaNusa = Setting::factory()->create(['company_name' => 'CV TIGA NUSA COMPUTER']);
        $this->topIt = Setting::factory()->create(['company_name' => 'CV TOP IT INTERNUSA']);
        $this->perdana = Setting::factory()->create(['company_name' => 'PERDANA']);

        $this->location = \Modules\Setting\Entities\Location::factory()->create(['setting_id' => $this->tigaNusa->id]);
        \Modules\Setting\Entities\Location::factory()->create(['setting_id' => $this->topIt->id]);
        \Modules\Setting\Entities\Location::factory()->create(['setting_id' => $this->perdana->id]);
        $this->user = \App\Models\User::factory()->create();
    }

    private function getDaizuLocationId(): int
    {
        $daizu = Setting::where('company_name', 'DAIZU NUSA')->first();
        if (!$daizu) {
            $daizu = Setting::factory()->create(['company_name' => 'DAIZU NUSA']);
        }
        $location = \Modules\Setting\Entities\Location::where('setting_id', $daizu->id)->first();
        if (!$location) {
            $location = \Modules\Setting\Entities\Location::factory()->create(['setting_id' => $daizu->id]);
        }
        return $location->id;
    }

    private function createXlsxFile(string $name, array $data): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($data, null, 'A1');

        $path = "imports/products/{$name}.xlsx";
        $fullPath = storage_path('app/' . $path);
        
        if (!file_exists(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0755, true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($fullPath);

        return $path;
    }

    public function test_marker_restricted_owner_updates_only_respective_settings()
    {
        $defaults = [
            'unit_id' => 1, 'stock_managed' => 1, 'product_quantity' => 0,
            'product_cost' => 0, 'product_price' => 0, 'is_sold' => 1, 'is_purchased' => 1,
            'purchase_price' => 0, 'sale_price' => 0, 'product_tax_type' => 1,
        ];

        \Illuminate\Support\Facades\DB::table('products')->insert([
            array_merge(['id' => 1, 'product_code' => 'GLOBAL-01', 'product_name' => 'GLOBAL LAPTOP', 'setting_id' => $this->tigaNusa->id], $defaults),
        ]);

        ProductPrice::create([
            'product_id' => 1,
            'setting_id' => $this->tigaNusa->id,
            'sale_price' => 1000,
            'tier_1_price' => 1000,
            'tier_2_price' => 1000,
            'last_purchase_price' => 500,
        ]);

        $path = $this->createXlsxFile('test_integration_marker_restricted', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['* Global Laptop', 'GLOBAL-01', '1,200.00', '10'],
            ['Global Laptop TP', 'GLOBAL-01', '1,300.00', '15'],
            ['Global Laptop', 'GLOBAL-01', '1,400.00', '20'],
        ]);

        $batch = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $this->location->id,
            'source_csv_path' => $path,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT,
        ]);

        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $rows = ProductImportRow::where('batch_id', $batch->id)->orderBy('row_number')->get();
        $this->assertCount(3, $rows);
        $this->assertEquals('imported', $rows[0]->status);
        $this->assertEquals('imported', $rows[1]->status);
        $this->assertEquals('imported', $rows[2]->status);

        // Asterisk marker updates only CV Tiga Nusa
        $priceTigaNusa = ProductPrice::where('product_id', 1)->where('setting_id', $this->tigaNusa->id)->first();
        $this->assertEquals(1200, $priceTigaNusa->sale_price);
        $this->assertEquals(1200, $priceTigaNusa->tier_1_price);
        $this->assertEquals(1200, $priceTigaNusa->tier_2_price);
        $this->assertEquals(500, $priceTigaNusa->last_purchase_price);

        // TP marker creates and updates only CV Top IT
        $priceTopIt = ProductPrice::where('product_id', 1)->where('setting_id', $this->topIt->id)->first();
        $this->assertNotNull($priceTopIt);
        $this->assertEquals(1300, $priceTopIt->sale_price);
        $this->assertEquals(1300, $priceTopIt->tier_1_price);
        $this->assertEquals(1300, $priceTopIt->tier_2_price);

        // Unmarked row creates and updates only Perdana
        $pricePerdana = ProductPrice::where('product_id', 1)->where('setting_id', $this->perdana->id)->first();
        $this->assertNotNull($pricePerdana);
        $this->assertEquals(1400, $pricePerdana->sale_price);
        $this->assertEquals(1400, $pricePerdana->tier_1_price);
        $this->assertEquals(1400, $pricePerdana->tier_2_price);
    }
    
    public function test_it_handles_duplicates_and_conflicts()
    {
        $defaults = [
            'unit_id' => 1, 'stock_managed' => 1, 'product_quantity' => 0,
            'product_cost' => 0, 'product_price' => 0, 'is_sold' => 1, 'is_purchased' => 1,
            'purchase_price' => 0, 'sale_price' => 0, 'product_tax_type' => 1,
        ];

        \Illuminate\Support\Facades\DB::table('products')->insert([
            array_merge(['id' => 1, 'product_code' => 'SKU-DUP', 'product_name' => 'DUP ITEM', 'setting_id' => $this->tigaNusa->id], $defaults),
            array_merge(['id' => 2, 'product_code' => 'SKU-CON', 'product_name' => 'CON ITEM', 'setting_id' => $this->tigaNusa->id], $defaults),
        ]);

        $path = $this->createXlsxFile('test_integration_duplicates', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['* Dup Item', 'SKU-DUP', '1000', '10'],
            ['* Dup Item', 'SKU-DUP', '1000', '10'], // Equivalent duplicate
            ['* Con Item', 'SKU-CON', '2000', '20'],
            ['* Con Item', 'SKU-CON', '2500', '20'], // Conflicting duplicate (same stock, different price)
        ]);

        $batch = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $this->location->id,
            'source_csv_path' => $path,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT,
        ]);

        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $rows = ProductImportRow::where('batch_id', $batch->id)->orderBy('row_number')->get();
        $this->assertCount(4, $rows);

        $this->assertEquals('imported', $rows[0]->status);
        $this->assertEquals('skipped', $rows[1]->status);

        $this->assertEquals('error', $rows[2]->status);
        $this->assertEquals('error', $rows[3]->status);
        $this->assertStringContainsString('Conflicting duplicate', $rows[2]->error_message);

        $priceDup = ProductPrice::where('product_id', 1)->where('setting_id', $this->tigaNusa->id)->first();
        $this->assertEquals(1000, $priceDup->sale_price);

        $priceCon = ProductPrice::where('product_id', 2)->where('setting_id', $this->tigaNusa->id)->first();
        $this->assertNull($priceCon); // Was not created due to conflict
    }

    public function test_daizu_precedence_overrides_markers_for_snapshot_prices()
    {
        $daizu = Setting::factory()->create(['company_name' => 'DAIZU NUSA']);
        $locationDaizu = \Modules\Setting\Entities\Location::factory()->create(['setting_id' => $daizu->id]);

        $defaults = [
            'unit_id' => 1, 'stock_managed' => 1, 'product_quantity' => 0,
            'product_cost' => 0, 'product_price' => 0, 'is_sold' => 1, 'is_purchased' => 1,
            'purchase_price' => 0, 'sale_price' => 0, 'product_tax_type' => 1,
        ];

        \Illuminate\Support\Facades\DB::table('products')->insert([
            array_merge(['id' => 1, 'product_code' => 'KEDELAI-01', 'product_name' => 'KEDELAI PRODUCT', 'setting_id' => $daizu->id], $defaults),
        ]);

        $path = $this->createXlsxFile('test_integration_daizu', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['* KEDELAI PRODUCT', 'KEDELAI-01', '5000', '100'],
            ['KEDELAI PRODUCT TP', 'KEDELAI-01', '5000', '100'],
        ]);

        $batch = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $this->location->id,
            'source_csv_path' => $path,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT,
        ]);

        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $batch->refresh();
        $rows = ProductImportRow::where('batch_id', $batch->id)->orderBy('row_number')->get();
        $this->assertCount(2, $rows);

        if ($rows[0]->status === 'error') {
            $this->fail("First row error: {$rows[0]->error_message}");
        }
        if ($rows[1]->status === 'error') {
            $this->fail("Second row error: {$rows[1]->error_message}");
        }

        $this->assertEquals('imported', $rows[0]->status);
        $this->assertEquals('skipped', $rows[1]->status, 'Second row should be skipped as duplicate');

        // Both rows should update only DAIZU, not the marker-resolved owners
        $priceDaizu = ProductPrice::where('product_id', 1)->where('setting_id', $daizu->id)->get();
        $this->assertCount(1, $priceDaizu);
        $this->assertEquals(5000, $priceDaizu->first()->sale_price);
        $this->assertEquals(5000, $priceDaizu->first()->tier_1_price);
        $this->assertEquals(5000, $priceDaizu->first()->tier_2_price);

        // Neither Tiga Nusa nor Top IT should have a price row
        $priceTigaNusa = ProductPrice::where('product_id', 1)->where('setting_id', $this->tigaNusa->id)->first();
        $this->assertNull($priceTigaNusa);

        $priceTopIt = ProductPrice::where('product_id', 1)->where('setting_id', $this->topIt->id)->first();
        $this->assertNull($priceTopIt);

        // No price row should exist for PERDANA either
        $pricePerdana = ProductPrice::where('product_id', 1)->where('setting_id', $this->perdana->id)->first();
        $this->assertNull($pricePerdana);

        // Verify DAIZU location has the stock
        $daizuStock = ProductStock::where('product_id', 1)->where('location_id', $locationDaizu->id)->first();
        $this->assertNotNull($daizuStock);
        $this->assertEquals(100, $daizuStock->quantity);

        // Verify CV TIGA NUSA location has no stock for DAIZU product
        $tiganusaLocations = \Modules\Setting\Entities\Location::where('setting_id', $this->tigaNusa->id)->get();
        foreach ($tiganusaLocations as $loc) {
            $stock = ProductStock::where('product_id', 1)->where('location_id', $loc->id)->first();
            $this->assertNull($stock, "CV TIGA NUSA location should not have stock for DAIZU product");
        }

        // Verify CV TOP IT location has no stock for DAIZU product
        $topitLocations = \Modules\Setting\Entities\Location::where('setting_id', $this->topIt->id)->get();
        foreach ($topitLocations as $loc) {
            $stock = ProductStock::where('product_id', 1)->where('location_id', $loc->id)->first();
            $this->assertNull($stock, "CV TOP IT location should not have stock for DAIZU product");
        }

        // Verify PERDANA location has no stock for DAIZU product
        $perdanaLocations = \Modules\Setting\Entities\Location::where('setting_id', $this->perdana->id)->get();
        foreach ($perdanaLocations as $loc) {
            $stock = ProductStock::where('product_id', 1)->where('location_id', $loc->id)->first();
            $this->assertNull($stock, "PERDANA location should not have stock for DAIZU product");
        }
    }
}
