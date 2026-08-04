<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Product\Entities\ProductImportBatch;
use Modules\Product\Entities\ProductImportRow;
use Modules\Product\Jobs\ProcessSalesPriceSnapshotBatch;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ProductSalesPriceSnapshotWorkbookReaderTest extends TestCase
{
    use RefreshDatabase;

    private $user;
    private $location;

    protected function setUp(): void
    {
        parent::setUp();
        $setting = Setting::factory()->create();
        $this->location = \Modules\Setting\Entities\Location::factory()->create(['setting_id' => $setting->id]);
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

    public function test_it_reads_valid_accurate_style_input()
    {
        $path = $this->createXlsxFile('test_valid', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock', 'OtherCol'],
            ['* Laptop', 'SKU-001', '400,000.00', '50', 'Ignore'],
            ['Mouse TP', '', '150,000', '100', 'Ignore'],
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
        // Since we only do staging, status should be completed
        $this->assertEquals('completed', $batch->status);
        $this->assertEquals(2, $batch->total_rows);

        $rows = ProductImportRow::where('batch_id', $batch->id)->orderBy('row_number')->get();
        $this->assertCount(2, $rows);

        $payload1 = $rows[0]->raw_json;
        $this->assertEquals('* Laptop', $payload1['name*']);
        $this->assertEquals('SKU-001', $payload1['productcode']);
        $this->assertEquals('400,000.00', $payload1['sellprice']);
        $this->assertEquals('50', $payload1['stock']);

        $payload2 = $rows[1]->raw_json;
        $this->assertEquals('Mouse TP', $payload2['name*']);
        $this->assertEquals('', $payload2['productcode']);
        $this->assertEquals('150,000', $payload2['sellprice']);
        $this->assertEquals('100', $payload2['stock']);
    }

    public function test_it_normalizes_bom_case_and_whitespace_in_headers()
    {
        $path = $this->createXlsxFile('test_headers', [
            ["\xEF\xBB\xBF  nAmE*  ", "  productCode\t", "\nSellprice ", "  STOCK  "],
            ['Keyboard', 'SKU-003', '500.00', '75'],
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
        $this->assertEquals('completed', $batch->status);

        $row = ProductImportRow::where('batch_id', $batch->id)->first();
        $payload = $row->raw_json;

        $this->assertEquals('Keyboard', $payload['name*']);
        $this->assertEquals('SKU-003', $payload['productcode']);
        $this->assertEquals('500', $payload['sellprice']);
        $this->assertEquals('75', $payload['stock']);
    }

    public function test_it_fails_batch_for_missing_required_headers()
    {
        $path = $this->createXlsxFile('test_missing_header', [
            ['Name*', 'ProductCode'], // Missing SellPrice and Stock
            ['Keyboard', 'SKU-003'],
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
        $this->assertEquals('failed', $batch->status);
        $this->assertStringContainsString('Missing required columns', $batch->error_message);
    }

    public function test_it_fails_batch_for_unreadable_workbook()
    {
        $path = 'imports/products/corrupt.xlsx';
        Storage::put($path, 'not a real excel file');

        $batch = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $this->location->id,
            'source_csv_path' => $path,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT,
        ]);

        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $batch->refresh();
        $this->assertEquals('failed', $batch->status);
        $this->assertStringContainsString('Failed to read XLSX file', $batch->error_message);
    }
}
