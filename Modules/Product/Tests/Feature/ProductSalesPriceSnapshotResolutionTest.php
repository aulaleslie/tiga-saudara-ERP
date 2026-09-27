<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductImportBatch;
use Modules\Product\Entities\ProductImportRow;
use Modules\Product\Jobs\ProcessSalesPriceSnapshotBatch;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ProductSalesPriceSnapshotResolutionTest extends TestCase
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

    public function test_it_resolves_products_and_settings()
    {
        $defaults = [
            'unit_id' => 1, 'stock_managed' => 1, 'product_quantity' => 0, 
            'product_cost' => 0, 'product_price' => 0, 'is_sold' => 1, 'is_purchased' => 1,
            'purchase_price' => 0, 'sale_price' => 0, 'product_tax_type' => 1,
        ];
        \Illuminate\Support\Facades\DB::table('products')->insert([
            array_merge(['product_code' => 'SKU-001', 'product_name' => 'LAPTOP', 'setting_id' => $this->tigaNusa->id], $defaults),
            array_merge(['product_code' => 'SKU-002', 'product_name' => 'MOUSE', 'setting_id' => $this->topIt->id], $defaults),
            array_merge(['product_code' => 'SKU-003', 'product_name' => 'KEYBOARD', 'setting_id' => $this->perdana->id], $defaults),
        ]);

        $path = $this->createXlsxFile('test_resolution', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['* Laptop', 'SKU-001', '400,000.00', '50'], // Asterisk -> Tiga Nusa
            ['Mouse TP', '', '150,000', '75'], // TP suffix -> Top IT. Matched by name
            ['Keyboard', 'SKU-003', '120,000', '100'], // No marker -> Perdana
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
        $this->assertEquals($this->tigaNusa->id, $rows[0]->result_metadata['setting_id']);
        $this->assertEquals(400000.0, $rows[0]->result_metadata['sell_price']);

        $this->assertEquals('imported', $rows[1]->status);
        $this->assertEquals($this->topIt->id, $rows[1]->result_metadata['setting_id']);
        $this->assertEquals(150000.0, $rows[1]->result_metadata['sell_price']);

        $this->assertEquals('imported', $rows[2]->status);
        $this->assertEquals($this->perdana->id, $rows[2]->result_metadata['setting_id']);
        $this->assertEquals(120000.0, $rows[2]->result_metadata['sell_price']);
    }

    public function test_it_fails_rows_with_resolution_errors()
    {
        $defaults = [
            'unit_id' => 1, 'stock_managed' => 1, 'product_quantity' => 0, 
            'product_cost' => 0, 'product_price' => 0, 'is_sold' => 1, 'is_purchased' => 1,
            'purchase_price' => 0, 'sale_price' => 0, 'product_tax_type' => 1,
        ];
        \Illuminate\Support\Facades\DB::table('products')->insert([
            array_merge(['product_code' => 'SKU-001', 'product_name' => 'LAPTOP', 'setting_id' => $this->tigaNusa->id], $defaults),
        ]);

        $path = $this->createXlsxFile('test_resolution_errors', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['* Unknown', 'SKU-999', '400,000.00', '50'], // Product doesn't exist
            ['* Laptop', 'SKU-001', '-50,000', '75'], // Negative price
            ['* Laptop', 'SKU-001', 'invalid', '100'], // Non-numeric price
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

        $this->assertEquals('skipped', $rows[0]->status);
        $this->assertStringContainsString('Product not found', $rows[0]->error_message);

        $this->assertEquals('skipped', $rows[1]->status);
        $this->assertStringContainsString('Blank or non-positive sell price', $rows[1]->error_message);

        $this->assertEquals('skipped', $rows[2]->status);
        $this->assertStringContainsString('Blank or non-positive sell price', $rows[2]->error_message);
    }
}
