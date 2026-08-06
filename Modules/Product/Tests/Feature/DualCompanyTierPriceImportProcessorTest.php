<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Product\Entities\ProductImportBatch;
use Modules\Product\Entities\ProductImportRow;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Jobs\ProcessDualCompanyTierPriceBatch;
use Modules\Setting\Entities\Setting;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class DualCompanyTierPriceImportProcessorTest extends TestCase
{
    use RefreshDatabase;

    private const TIGA_NUSA = 'CV TIGA NUSA COMPUTER';
    private const TOP_IT = 'CV TOP IT INTERNUSA';

    private const HEADERS = [
        'Nama Produk', 'Harga Jual', 'Harga Tier 1', 'Harga Tier 2', 'Harga Beli Terakhir', 'Harga Beli Rata-rata',
    ];

    private Setting $tigaNusa;
    private Setting $topIt;
    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        // No locations are created: this import must never depend on one.
        $this->tigaNusa = Setting::factory()->create(['company_name' => self::TIGA_NUSA]);
        $this->topIt = Setting::factory()->create(['company_name' => self::TOP_IT]);
        $this->user = \App\Models\User::factory()->create();
    }

    private function createProduct(string $name, ?int $settingId = null): int
    {
        return DB::table('products')->insertGetId([
            'product_code' => 'SKU-' . strtoupper(bin2hex(random_bytes(3))),
            'product_name' => $name,
            'setting_id' => $settingId ?? $this->tigaNusa->id,
            'unit_id' => 1,
            'stock_managed' => 1,
            'product_quantity' => 0,
            'product_cost' => 0,
            'product_price' => 0,
            'is_sold' => 1,
            'is_purchased' => 1,
            'purchase_price' => 0,
            'sale_price' => 0,
            'product_tax_type' => 1,
        ]);
    }

    private function createPrice(int $productId, int $settingId, array $attributes = []): ProductPrice
    {
        return ProductPrice::create(array_merge([
            'product_id' => $productId,
            'setting_id' => $settingId,
            'sale_price' => 100,
            'tier_1_price' => 90,
            'tier_2_price' => 80,
            'last_purchase_price' => 70,
            'average_purchase_price' => 60,
        ], $attributes));
    }

    /**
     * @param array<string, array<int, array<int, mixed>>> $sheets Sheet title => data rows (row 5 onward)
     */
    private function createWorkbook(string $name, array $sheets, ?array $headerOverrides = null): string
    {
        $spreadsheet = new Spreadsheet();
        $index = 0;

        foreach ($sheets as $title => $dataRows) {
            $sheet = $index === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet($index);
            $sheet->setTitle($title);
            $sheet->setCellValue('A1', $title);
            $sheet->fromArray([$headerOverrides ?? self::HEADERS], null, 'A4');

            // Write cells explicitly: fromArray() skips a literal 0, which this
            // import must distinguish from a blank cell.
            foreach (array_values($dataRows) as $rowOffset => $cells) {
                foreach (array_values($cells) as $columnOffset => $value) {
                    if ($value === '') {
                        continue;
                    }
                    $sheet->setCellValueByColumnAndRow($columnOffset + 1, 5 + $rowOffset, $value);
                }
            }
            $index++;
        }

        $path = "imports/products/{$name}.xlsx";
        $fullPath = storage_path('app/' . $path);
        if (!file_exists(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0755, true);
        }
        (new Xlsx($spreadsheet))->save($fullPath);

        return $path;
    }

    private function runImport(string $path): ProductImportBatch
    {
        $batch = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => null,
            'source_csv_path' => $path,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_DUAL_COMPANY_TIER_PRICE,
        ]);

        (new ProcessDualCompanyTierPriceBatch($batch->id))->handle();

        return $batch->fresh();
    }

    /** @return \Illuminate\Support\Collection<int, ProductImportRow> */
    private function rowsOf(ProductImportBatch $batch)
    {
        return ProductImportRow::where('batch_id', $batch->id)->orderBy('row_number')->get();
    }

    // --- 4.2 Structure validation and isolation -------------------------------

    public function test_it_fails_the_batch_when_a_required_worksheet_is_missing(): void
    {
        $productId = $this->createProduct('LAPTOP');
        $price = $this->createPrice($productId, $this->tigaNusa->id);

        $path = $this->createWorkbook('missing_sheet', [
            self::TIGA_NUSA => [['LAPTOP', 500, 450, 400, '', '']],
        ]);

        $batch = $this->runImport($path);

        $this->assertSame('failed', $batch->status);
        $this->assertStringContainsString(self::TOP_IT, $batch->error_message);
        $this->assertCount(0, $this->rowsOf($batch));
        $this->assertEquals(100.00, (float) $price->fresh()->sale_price);
    }

    public function test_it_fails_the_batch_when_an_unexpected_worksheet_is_present(): void
    {
        $productId = $this->createProduct('LAPTOP');
        $price = $this->createPrice($productId, $this->tigaNusa->id);

        $path = $this->createWorkbook('extra_sheet', [
            self::TIGA_NUSA => [['LAPTOP', 500, 450, 400, '', '']],
            self::TOP_IT => [],
            'CV LAIN' => [],
        ]);

        $batch = $this->runImport($path);

        $this->assertSame('failed', $batch->status);
        $this->assertStringContainsString('Unexpected worksheet', $batch->error_message);
        $this->assertCount(0, $this->rowsOf($batch));
        $this->assertEquals(100.00, (float) $price->fresh()->sale_price);
    }

    public function test_it_fails_the_batch_when_a_required_header_is_missing(): void
    {
        $productId = $this->createProduct('LAPTOP');
        $price = $this->createPrice($productId, $this->tigaNusa->id);

        $path = $this->createWorkbook(
            'missing_header',
            [self::TIGA_NUSA => [['LAPTOP', 500, 400, '', '']], self::TOP_IT => []],
            ['Nama Produk', 'Harga Jual', 'Harga Tier 2', 'Harga Beli Terakhir', 'Harga Beli Rata-rata']
        );

        $batch = $this->runImport($path);

        $this->assertSame('failed', $batch->status);
        $this->assertStringContainsString('Harga Tier 1', $batch->error_message);
        $this->assertCount(0, $this->rowsOf($batch));
        $this->assertEquals(100.00, (float) $price->fresh()->sale_price);
    }

    public function test_each_worksheet_updates_only_its_own_company_price_row(): void
    {
        $productId = $this->createProduct('LAPTOP');
        $tigaNusaPrice = $this->createPrice($productId, $this->tigaNusa->id);
        $topItPrice = $this->createPrice($productId, $this->topIt->id);

        $path = $this->createWorkbook('isolation', [
            self::TIGA_NUSA => [['LAPTOP', 500, 450, 400, '', '']],
            self::TOP_IT => [['LAPTOP', 900, 850, 800, '', '']],
        ]);

        $batch = $this->runImport($path);

        $this->assertSame('completed', $batch->status);

        $tigaNusaPrice->refresh();
        $this->assertEquals(500.00, (float) $tigaNusaPrice->sale_price);
        $this->assertEquals(450.00, (float) $tigaNusaPrice->tier_1_price);
        $this->assertEquals(400.00, (float) $tigaNusaPrice->tier_2_price);

        $topItPrice->refresh();
        $this->assertEquals(900.00, (float) $topItPrice->sale_price);
        $this->assertEquals(850.00, (float) $topItPrice->tier_1_price);
        $this->assertEquals(800.00, (float) $topItPrice->tier_2_price);

        $rows = $this->rowsOf($batch);
        $this->assertCount(2, $rows);
        $this->assertSame(self::TIGA_NUSA, $rows[0]->result_metadata['worksheet']);
        $this->assertSame(self::TOP_IT, $rows[1]->result_metadata['worksheet']);
    }

    public function test_it_does_not_mutate_stock_or_purchase_costs(): void
    {
        $productId = $this->createProduct('LAPTOP');
        $price = $this->createPrice($productId, $this->tigaNusa->id);

        $path = $this->createWorkbook('no_stock', [
            self::TIGA_NUSA => [['LAPTOP', 500, 450, 400, 12345, 6789]],
            self::TOP_IT => [],
        ]);

        $this->runImport($path);

        $price->refresh();
        $this->assertEquals(70.00, (float) $price->last_purchase_price);
        $this->assertEquals(60.00, (float) $price->average_purchase_price);

        $this->assertDatabaseCount('product_stocks', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertEquals(0, DB::table('products')->where('id', $productId)->value('product_quantity'));
    }

    // --- 4.3 Independent tier semantics ---------------------------------------

    public function test_it_preserves_tiers_whose_cells_are_blank(): void
    {
        $productId = $this->createProduct('LAPTOP');
        $price = $this->createPrice($productId, $this->tigaNusa->id);

        $path = $this->createWorkbook('partial', [
            self::TIGA_NUSA => [['LAPTOP', '', 450, '', '', '']],
            self::TOP_IT => [],
        ]);

        $batch = $this->runImport($path);

        $price->refresh();
        $this->assertEquals(100.00, (float) $price->sale_price);
        $this->assertEquals(450.00, (float) $price->tier_1_price);
        $this->assertEquals(80.00, (float) $price->tier_2_price);

        $meta = $this->rowsOf($batch)[0]->result_metadata;
        $this->assertSame(['tier_1_price'], array_keys($meta['supplied_tiers']));
        $this->assertEquals(450.0, $meta['supplied_tiers']['tier_1_price']);
        $this->assertTrue($meta['price_changed']);
    }

    public function test_zero_is_stored_as_an_explicit_price(): void
    {
        $productId = $this->createProduct('LAPTOP');
        $price = $this->createPrice($productId, $this->tigaNusa->id);

        $path = $this->createWorkbook('zero', [
            self::TIGA_NUSA => [['LAPTOP', 0, '', '', '', '']],
            self::TOP_IT => [],
        ]);

        $this->runImport($path);

        $price->refresh();
        $this->assertEquals(0.00, (float) $price->sale_price);
        $this->assertEquals(90.00, (float) $price->tier_1_price);
        $this->assertEquals(80.00, (float) $price->tier_2_price);
    }

    public function test_a_row_with_all_blank_tiers_is_skipped(): void
    {
        $productId = $this->createProduct('LAPTOP');
        $price = $this->createPrice($productId, $this->tigaNusa->id);

        // A name-only row is still a data row, but supplies no tier at all.
        $path = $this->createWorkbook('all_blank', [
            self::TIGA_NUSA => [['LAPTOP', '', '', '', '', '']],
            self::TOP_IT => [],
        ]);

        $batch = $this->runImport($path);

        $row = $this->rowsOf($batch)[0];
        $this->assertSame('skipped', $row->status);
        $this->assertStringContainsString('No selling tier supplied', $row->error_message);

        $price->refresh();
        $this->assertEquals(100.00, (float) $price->sale_price);
        $this->assertEquals(90.00, (float) $price->tier_1_price);
        $this->assertEquals(80.00, (float) $price->tier_2_price);
    }

    public function test_it_preserves_non_selling_price_fields_on_a_full_tier_update(): void
    {
        $productId = $this->createProduct('LAPTOP');
        $price = $this->createPrice($productId, $this->tigaNusa->id, [
            'last_purchase_price' => 111,
            'average_purchase_price' => 222,
        ]);

        $path = $this->createWorkbook('full', [
            self::TIGA_NUSA => [['LAPTOP', '1,500.50', 1400, 1300, '', '']],
            self::TOP_IT => [],
        ]);

        $batch = $this->runImport($path);

        $price->refresh();
        $this->assertEquals(1500.50, (float) $price->sale_price);
        $this->assertEquals(1400.00, (float) $price->tier_1_price);
        $this->assertEquals(1300.00, (float) $price->tier_2_price);
        $this->assertEquals(111.00, (float) $price->last_purchase_price);
        $this->assertEquals(222.00, (float) $price->average_purchase_price);

        $meta = $this->rowsOf($batch)[0]->result_metadata;
        $this->assertEquals(100.0, (float) $meta['previous_tiers']['sale_price']);
        $this->assertEquals(1500.50, (float) $meta['resulting_tiers']['sale_price']);
        $this->assertSame('normalized_name', $meta['match_strategy']);
    }

    public function test_it_reports_an_invalid_tier_value_without_changing_prices(): void
    {
        $productId = $this->createProduct('LAPTOP');
        $price = $this->createPrice($productId, $this->tigaNusa->id);

        $path = $this->createWorkbook('invalid', [
            self::TIGA_NUSA => [['LAPTOP', 'abc', 450, '', '', '']],
            self::TOP_IT => [],
        ]);

        $batch = $this->runImport($path);

        $row = $this->rowsOf($batch)[0];
        $this->assertSame('error', $row->status);
        $this->assertStringContainsString('Harga Jual', $row->error_message);
        $this->assertEquals(100.00, (float) $price->fresh()->sale_price);
        $this->assertEquals(90.00, (float) $price->fresh()->tier_1_price);
    }

    // --- 4.4 Matching, duplicates, and rollback --------------------------------

    public function test_it_skips_an_unmatched_product_without_creating_records(): void
    {
        $path = $this->createWorkbook('unmatched', [
            self::TIGA_NUSA => [['TIDAK ADA', 500, 450, 400, '', '']],
            self::TOP_IT => [],
        ]);

        $batch = $this->runImport($path);

        $row = $this->rowsOf($batch)[0];
        $this->assertSame('skipped', $row->status);
        $this->assertStringContainsString('Product not found', $row->error_message);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_prices', 0);
    }

    public function test_it_errors_an_ambiguous_product_name(): void
    {
        $firstId = $this->createProduct('LAPTOP');
        $secondId = $this->createProduct('laptop');
        $firstPrice = $this->createPrice($firstId, $this->tigaNusa->id);
        $secondPrice = $this->createPrice($secondId, $this->tigaNusa->id);

        $path = $this->createWorkbook('ambiguous', [
            self::TIGA_NUSA => [['LAPTOP', 500, 450, 400, '', '']],
            self::TOP_IT => [],
        ]);

        $batch = $this->runImport($path);

        $row = $this->rowsOf($batch)[0];
        $this->assertSame('error', $row->status);
        $this->assertStringContainsString('Ambiguous product name', $row->error_message);
        $this->assertCount(2, $row->result_metadata['ambiguous_candidates']);
        $this->assertEquals(100.00, (float) $firstPrice->fresh()->sale_price);
        $this->assertEquals(100.00, (float) $secondPrice->fresh()->sale_price);
    }

    public function test_it_skips_a_row_whose_company_has_no_price_row(): void
    {
        $productId = $this->createProduct('LAPTOP');
        $this->createPrice($productId, $this->tigaNusa->id);

        $path = $this->createWorkbook('missing_price_row', [
            self::TIGA_NUSA => [],
            self::TOP_IT => [['LAPTOP', 900, 850, 800, '', '']],
        ]);

        $batch = $this->runImport($path);

        $row = $this->rowsOf($batch)[0];
        $this->assertSame('skipped', $row->status);
        $this->assertStringContainsString('No existing price row', $row->error_message);
        $this->assertDatabaseCount('product_prices', 1);
    }

    public function test_conflicting_duplicate_rows_leave_the_target_unchanged(): void
    {
        $productId = $this->createProduct('LAPTOP');
        $price = $this->createPrice($productId, $this->tigaNusa->id);

        $path = $this->createWorkbook('conflict', [
            self::TIGA_NUSA => [
                ['LAPTOP', 500, 450, 400, '', ''],
                ['LAPTOP', 600, 450, 400, '', ''],
            ],
            self::TOP_IT => [],
        ]);

        $batch = $this->runImport($path);

        foreach ($this->rowsOf($batch) as $row) {
            $this->assertSame('error', $row->status);
            $this->assertStringContainsString('Conflicting duplicate', $row->error_message);
        }

        $price->refresh();
        $this->assertEquals(100.00, (float) $price->sale_price);
        $this->assertEquals(90.00, (float) $price->tier_1_price);
        $this->assertEquals(80.00, (float) $price->tier_2_price);
    }

    public function test_equivalent_duplicate_rows_apply_once_and_mark_later_rows_duplicate(): void
    {
        $productId = $this->createProduct('LAPTOP');
        $price = $this->createPrice($productId, $this->tigaNusa->id);

        $path = $this->createWorkbook('equivalent', [
            self::TIGA_NUSA => [
                ['LAPTOP', 500, '', 400, '', ''],
                ['LAPTOP', 500, 450, '', '', ''],
            ],
            self::TOP_IT => [],
        ]);

        $batch = $this->runImport($path);

        $rows = $this->rowsOf($batch);
        $this->assertSame('imported', $rows[0]->status);
        $this->assertSame('skipped', $rows[1]->status);
        $this->assertSame('duplicate', $rows[1]->result_metadata['outcome']);

        $price->refresh();
        $this->assertEquals(500.00, (float) $price->sale_price);
        $this->assertEquals(450.00, (float) $price->tier_1_price);
        $this->assertEquals(400.00, (float) $price->tier_2_price);

        $this->assertSame(1, $batch->fresh()->success_rows);
    }

    public function test_a_persistence_failure_rolls_back_and_errors_the_group(): void
    {
        $productId = $this->createProduct('LAPTOP');
        $price = $this->createPrice($productId, $this->tigaNusa->id);

        $path = $this->createWorkbook('rollback', [
            self::TIGA_NUSA => [['LAPTOP', 500, 450, 400, '', '']],
            self::TOP_IT => [],
        ]);

        $batch = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => null,
            'source_csv_path' => $path,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_DUAL_COMPANY_TIER_PRICE,
        ]);

        // Force the price write to fail after the transaction has opened.
        ProductPrice::saving(function () {
            throw new \RuntimeException('simulated persistence failure');
        });

        try {
            (new ProcessDualCompanyTierPriceBatch($batch->id))->handle();
        } finally {
            ProductPrice::flushEventListeners();
        }

        $row = $this->rowsOf($batch)[0];
        $this->assertSame('error', $row->status);
        $this->assertStringContainsString('Database error during price update', $row->error_message);

        $price->refresh();
        $this->assertEquals(100.00, (float) $price->sale_price);
        $this->assertEquals(90.00, (float) $price->tier_1_price);
        $this->assertEquals(80.00, (float) $price->tier_2_price);
    }
}
