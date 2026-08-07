<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductImportBatch;
use Modules\Product\Jobs\ProcessSalesPriceSnapshotBatch;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class SalesPriceSnapshotImportTest extends TestCase
{
    use RefreshDatabase;

    private Setting $tigaNusa;
    private Setting $topIt;
    private Setting $perdana;

    protected function setUp(): void
    {
        parent::setUp();

        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);
        Gate::before(fn() => true);

        $this->tigaNusa = Setting::factory()->create(['company_name' => 'CV TIGA NUSA COMPUTER']);
        $this->topIt = Setting::factory()->create(['company_name' => 'CV TOP IT INTERNUSA']);
        $this->perdana = Setting::factory()->create(['company_name' => 'PERDANA']);

        Location::factory()->create(['setting_id' => $this->tigaNusa->id]);
        Location::factory()->create(['setting_id' => $this->topIt->id]);
        Location::factory()->create(['setting_id' => $this->perdana->id]);
    }

    private function createXlsxUpload(array $rows, string $filename = 'prices.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($rows, null, 'A1');

        $tempPath = tempnam(sys_get_temp_dir(), 'xlsx_');
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        return new UploadedFile(
            $tempPath,
            $filename,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    public function test_code_match_succeeds_for_canonical_equivalents()
    {
        $unit = \Modules\Setting\Entities\Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);
        $product = Product::create([
            'product_name' => 'LAPTOP ACER',
            'product_code' => 'LAPTOP-ACER-001',
            'product_quantity' => 0,
            'product_price' => 0,
            'product_cost' => 0,
            'setting_id' => $this->tigaNusa->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
        ]);

        $file = $this->createXlsxUpload([
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['*LAPTOP ACER', 'LAPTOP-ACER-001', 500000.00, 10],
        ]);

        $response = $this->post(route('products.sales-price-snapshot.upload'), ['file' => $file]);
        $response->assertSessionHasNoErrors();

        $batch = ProductImportBatch::first();
        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $batch->refresh();
        $this->assertEquals('completed', $batch->status);
        $this->assertEquals(1, $batch->success_rows);

        $productPrice = \Modules\Product\Entities\ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->tigaNusa->id)->first();
        $this->assertEquals(500000.00, $productPrice->sale_price);
    }

    public function test_code_match_with_trailing_tp_marker()
    {
        $unit = \Modules\Setting\Entities\Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);
        $product = Product::create([
            'product_name' => 'KEYBOARD LOGITECH',
            'product_code' => 'KBD-LOG-001',
            'product_quantity' => 0,
            'product_price' => 0,
            'product_cost' => 0,
            'setting_id' => $this->topIt->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
        ]);

        $file = $this->createXlsxUpload([
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['KEYBOARD LOGITECH TP', 'KBD-LOG-001', 350000.00, 5],
        ]);

        $this->post(route('products.sales-price-snapshot.upload'), ['file' => $file]);

        $batch = ProductImportBatch::latest()->first();
        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $batch->refresh();
        $this->assertEquals(1, $batch->success_rows);
    }

    public function test_code_match_with_whitespace_variants()
    {
        $unit = \Modules\Setting\Entities\Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);
        $product = Product::create([
            'product_name' => 'MOUSE WIRELESS',
            'product_code' => 'MOUSE-WL-001',
            'product_quantity' => 0,
            'product_price' => 0,
            'product_cost' => 0,
            'setting_id' => $this->perdana->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
        ]);

        $file = $this->createXlsxUpload([
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['*  MOUSE   WIRELESS  ', 'MOUSE-WL-001', 150000.00, 20],
        ]);

        $this->post(route('products.sales-price-snapshot.upload'), ['file' => $file]);

        $batch = ProductImportBatch::latest()->first();
        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $batch->refresh();
        $this->assertEquals(1, $batch->success_rows);
    }

    public function test_code_match_with_casing_variants()
    {
        $unit = \Modules\Setting\Entities\Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);
        $product = Product::create([
            'product_name' => 'MONITOR LG 24',
            'product_code' => 'MON-LG-24',
            'product_quantity' => 0,
            'product_price' => 0,
            'product_cost' => 0,
            'setting_id' => $this->tigaNusa->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
        ]);

        $file = $this->createXlsxUpload([
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['*monitor lg 24', 'MON-LG-24', 2500000.00, 3],
        ]);

        $this->post(route('products.sales-price-snapshot.upload'), ['file' => $file]);

        $batch = ProductImportBatch::latest()->first();
        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $batch->refresh();
        $this->assertEquals(1, $batch->success_rows);
    }

    public function test_laptop_retained_without_truncation()
    {
        $unit = \Modules\Setting\Entities\Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);
        $product = Product::create([
            'product_name' => 'LAPTOP',
            'product_code' => 'LAPTOP-001',
            'product_quantity' => 0,
            'product_price' => 0,
            'product_cost' => 0,
            'setting_id' => $this->tigaNusa->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
        ]);

        $file = $this->createXlsxUpload([
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['*LAPTOP', 'LAPTOP-001', 3000000.00, 2],
        ]);

        $this->post(route('products.sales-price-snapshot.upload'), ['file' => $file]);

        $batch = ProductImportBatch::latest()->first();
        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $batch->refresh();
        $this->assertEquals(1, $batch->success_rows, 'LAPTOP should be retained as is, not truncated');
    }

    public function test_punctuation_differences_produce_code_name_disagreement()
    {
        $unit = \Modules\Setting\Entities\Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);
        $product = Product::create([
            'product_name' => 'ALFA-INK CANON BLACK',
            'product_code' => 'ALFA-INK-001',
            'product_quantity' => 0,
            'product_price' => 0,
            'product_cost' => 0,
            'setting_id' => $this->tigaNusa->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
        ]);

        $file = $this->createXlsxUpload([
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['*ALFA INK CANON BLACK', 'ALFA-INK-001', 250000.00, 5],
        ]);

        $this->post(route('products.sales-price-snapshot.upload'), ['file' => $file]);

        $batch = ProductImportBatch::latest()->first();
        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $batch->refresh();
        // Code/name disagreement: hyphen vs space difference
        $this->assertEquals(0, $batch->success_rows);
        $this->assertEquals(1, $batch->error_rows);

        $rows = \Modules\Product\Entities\ProductImportRow::where('batch_id', $batch->id)->get();
        $this->assertStringContainsString('Code/name disagreement', $rows->first()->error_message);
    }

    public function test_word_differences_produce_code_name_disagreement()
    {
        $unit = \Modules\Setting\Entities\Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);
        $product = Product::create([
            'product_name' => 'MOUSE VOTRE / SANURPRO / VOXY',
            'product_code' => 'MOUSE-VOL-001',
            'product_quantity' => 0,
            'product_price' => 0,
            'product_cost' => 0,
            'setting_id' => $this->tigaNusa->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
        ]);

        $file = $this->createXlsxUpload([
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['*MOUSE VOTRE / VOXY', 'MOUSE-VOL-001', 180000.00, 15],
        ]);

        $this->post(route('products.sales-price-snapshot.upload'), ['file' => $file]);

        $batch = ProductImportBatch::latest()->first();
        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $batch->refresh();
        // Missing SANURPRO variant: word difference produces disagreement
        $this->assertEquals(0, $batch->success_rows);
        $this->assertEquals(1, $batch->error_rows);
    }

    public function test_no_code_path_resolves_through_product_resolver()
    {
        $unit = \Modules\Setting\Entities\Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);
        $product = Product::create([
            'product_name' => 'ADAPTER POWER 90W',
            'product_quantity' => 0,
            'product_price' => 0,
            'product_cost' => 0,
            'setting_id' => $this->tigaNusa->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
        ]);

        $file = $this->createXlsxUpload([
            ['Name*', 'SellPrice', 'Stock'],
            ['*ADAPTER POWER 90W', 450000.00, 8],
        ]);

        $this->post(route('products.sales-price-snapshot.upload'), ['file' => $file]);

        $batch = ProductImportBatch::latest()->first();
        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $batch->refresh();
        // Should resolve via ProductResolver without requiring code
        $this->assertEquals(1, $batch->success_rows);
    }

    public function test_no_code_path_does_not_create_missing_products()
    {
        $file = $this->createXlsxUpload([
            ['Name*', 'SellPrice', 'Stock'],
            ['*NONEXISTENT PRODUCT XYZ', 100000.00, 5],
        ]);

        $this->post(route('products.sales-price-snapshot.upload'), ['file' => $file]);

        $batch = ProductImportBatch::latest()->first();
        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $batch->refresh();
        // No creation: must fail (product not found)
        $this->assertEquals(0, $batch->success_rows);
        $this->assertEquals(1, $batch->processed_rows);
    }
}
