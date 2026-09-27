<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductImportBatch;
use Modules\Product\Entities\ProductImportRow;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Product\Jobs\ProcessSalesPriceSnapshotBatch;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Location;
use Tests\TestCase;
use App\Models\User;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ProductSalesPriceStockSnapshotIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private $user;
    private $location;
    private $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setting = Setting::factory()->create([
            'company_name' => 'CV TIGA NUSA COMPUTER',
            'is_pkp' => true
        ]);
        $this->location = Location::factory()->create(['setting_id' => $this->setting->id]);
        $this->user = User::factory()->create();
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

    public function test_it_requires_stock_column_in_workbook()
    {
        $path = $this->createXlsxFile('test_missing_stock', [
            ['Name*', 'ProductCode', 'SellPrice'],
            ['* Keyboard', 'SKU-001', '500,000.00'],
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

    public function test_it_stages_stock_from_valid_workbook()
    {
        $path = $this->createXlsxFile('test_valid_stock', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['* Keyboard', 'SKU-001', '500,000.00', '100'],
            ['Mouse TP', '', '150,000', '-50'],
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
        $this->assertEquals(2, $batch->total_rows);

        $rows = ProductImportRow::where('batch_id', $batch->id)->orderBy('row_number')->get();
        $this->assertCount(2, $rows);

        $payload1 = $rows[0]->raw_json;
        $this->assertEquals('* Keyboard', $payload1['name*']);
        $this->assertEquals('500,000.00', $payload1['sellprice']);
        $this->assertEquals('100', $payload1['stock']);

        $payload2 = $rows[1]->raw_json;
        $this->assertEquals('Mouse TP', $payload2['name*']);
        $this->assertEquals('150,000', $payload2['sellprice']);
        $this->assertEquals('-50', $payload2['stock']);
    }

    public function test_it_applies_price_and_stock_snapshot_atomically()
    {
        // Create existing product
        $product = Product::create([
            'product_name' => 'Keyboard',
            'product_code' => 'SKU-001',
            'product_quantity' => 50,
            'setting_id' => $this->setting->id,
            'unit_id' => 1,
            'stock_managed' => 1,
            'product_cost' => 0,
            'product_price' => 0,
            'is_sold' => 1,
            'is_purchased' => 1,
            'purchase_price' => 0,
            'sale_price' => 0,
            'product_tax_type' => 1
        ]);

        // Create initial stock and price
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 50,
            'quantity_tax' => 50,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 400000,
            'tier_1_price' => 400000,
            'tier_2_price' => 400000,
        ]);

        // Create prior ledger transaction to establish baseline quantity
        Transaction::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'type' => 'ADJ',
            'quantity' => 50,
            'current_quantity' => 50,
            'previous_quantity' => 0,
            'after_quantity' => 50,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 50,
            'quantity_tax' => 50,
            'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'user_id' => $this->user->id,
            'reason' => 'Initial stock',
        ]);

        $path = $this->createXlsxFile('test_atomic_mutation', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['* Keyboard', 'SKU-001', '500,000.00', '100'],
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
        $this->assertEquals(1, $batch->success_rows);

        // Verify price was updated
        $price = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->setting->id)
            ->first();
        $this->assertEquals(500000, $price->sale_price);
        $this->assertEquals(500000, $price->tier_1_price);
        $this->assertEquals(500000, $price->tier_2_price);

        // Verify stock was updated
        $stock = ProductStock::where('product_id', $product->id)
            ->where('location_id', $this->location->id)
            ->first();
        $this->assertEquals(100, $stock->quantity);
        $this->assertEquals(100, $stock->quantity_tax); // PKP setting
        $this->assertEquals(0, $stock->quantity_non_tax);

        // Verify product aggregate quantity was updated
        $product->refresh();
        $this->assertEquals(100, $product->product_quantity);

        // Verify ADJ transaction was created (should be the latest one)
        $txn = Transaction::where('product_id', $product->id)
            ->where('location_id', $this->location->id)
            ->where('type', 'ADJ')
            ->latest('id')
            ->first();
        $this->assertNotNull($txn);
        $this->assertEquals(50, (int)$txn->quantity); // Delta: 100 - 50
        $this->assertEquals(100, (int)$txn->after_quantity_at_location);

        // Verify row metadata contains stock effects
        $row = ProductImportRow::where('batch_id', $batch->id)->first();
        $meta = $row->result_metadata;
        $this->assertEquals(100, $meta['imported_stock']);
        $this->assertEquals(50, $meta['previous_quantity']);
        $this->assertEquals(100, $meta['after_quantity']);
        $this->assertEquals(50, $meta['delta_quantity']);
        $this->assertEquals(500000, $meta['new_tiers']['sale_price']);
    }

    public function test_it_rejects_unmatched_products()
    {
        $path = $this->createXlsxFile('test_unmatched', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['* NonExistent Product', 'SKU-999', '500,000.00', '100'],
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
        $this->assertEquals(0, $batch->success_rows);
        $this->assertEquals(0, $batch->error_rows); // Unmatched products are skipped, not errors

        $row = ProductImportRow::where('batch_id', $batch->id)->first();
        $this->assertEquals('skipped', $row->status);
        $this->assertStringContainsString('Product not found', $row->error_message);
    }

    public function test_it_detects_duplicate_conflicts()
    {
        // Create product
        $product = Product::create(['product_name' => 'Keyboard', 'setting_id' => $this->setting->id, 'unit_id' => 1, 'stock_managed' => 1, 'product_quantity' => 0, 'product_cost' => 0, 'product_price' => 0, 'is_sold' => 1, 'is_purchased' => 1, 'purchase_price' => 0, 'sale_price' => 0, 'product_tax_type' => 1]);
        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 400000,
            'tier_1_price' => 400000,
            'tier_2_price' => 400000,
        ]);
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 50,
            'quantity_tax' => 50,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Two rows with same target but different stock values
        $path = $this->createXlsxFile('test_duplicate_conflict', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['* Keyboard', '', '500,000.00', '100'],
            ['* Keyboard', '', '500,000.00', '200'], // Conflict on stock
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
        $this->assertEquals(0, $batch->success_rows);
        $this->assertEquals(2, $batch->error_rows);

        $rows = ProductImportRow::where('batch_id', $batch->id)->get();
        foreach ($rows as $row) {
            $this->assertStringContainsString('Conflicting', $row->error_message);
        }

        // Stock should not have changed
        $stock = ProductStock::where('product_id', $product->id)->first();
        $this->assertEquals(50, $stock->quantity);
    }

    public function test_it_handles_duplicate_equivalent_rows()
    {
        $product = Product::create(['product_name' => 'Keyboard', 'setting_id' => $this->setting->id, 'unit_id' => 1, 'stock_managed' => 1, 'product_quantity' => 0, 'product_cost' => 0, 'product_price' => 0, 'is_sold' => 1, 'is_purchased' => 1, 'purchase_price' => 0, 'sale_price' => 0, 'product_tax_type' => 1]);
        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 400000,
            'tier_1_price' => 400000,
            'tier_2_price' => 400000,
        ]);
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 50,
            'quantity_tax' => 50,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Two identical rows
        $path = $this->createXlsxFile('test_duplicate_equivalent', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['* Keyboard', '', '500,000.00', '100'],
            ['* Keyboard', '', '500,000.00', '100'], // Identical
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
        $this->assertEquals(1, $batch->success_rows); // Only first applied
        $this->assertEquals(0, $batch->error_rows);

        $rows = ProductImportRow::where('batch_id', $batch->id)->orderBy('row_number')->get();
        $this->assertEquals('imported', $rows[0]->status);
        $this->assertEquals('skipped', $rows[1]->status);
        $this->assertStringContainsString('Equivalent duplicate', $rows[1]->error_message);
    }

    public function test_it_handles_non_pkp_owner_stock_bucket()
    {
        $setting = Setting::factory()->create([
            'company_name' => 'PERDANA',
            'is_pkp' => false
        ]);
        $location = Location::factory()->create(['setting_id' => $setting->id]);

        $product = Product::create(['product_name' => 'Mouse', 'setting_id' => $setting->id, 'unit_id' => 1, 'stock_managed' => 1, 'product_quantity' => 0, 'product_cost' => 0, 'product_price' => 0, 'is_sold' => 1, 'is_purchased' => 1, 'purchase_price' => 0, 'sale_price' => 0, 'product_tax_type' => 1]);
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 30,
            'quantity_tax' => 0,
            'quantity_non_tax' => 30,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $path = $this->createXlsxFile('test_non_pkp_bucket', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['Mouse', 'SKU-002', '150,000.00', '75'],
        ]);

        $batch = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $location->id,
            'source_csv_path' => $path,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT,
        ]);

        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $batch->refresh();
        $this->assertEquals(1, $batch->success_rows);

        // Verify non-PKP bucket assignment
        $stock = ProductStock::where('product_id', $product->id)
            ->where('location_id', $location->id)
            ->first();
        $this->assertEquals(75, $stock->quantity);
        $this->assertEquals(0, $stock->quantity_tax); // Non-PKP
        $this->assertEquals(75, $stock->quantity_non_tax);
    }

    public function test_it_routes_daizu_products_to_daizu_setting()
    {
        $daizuSetting = Setting::factory()->create([
            'company_name' => 'DAIZU KEDELAI Indonesia',
            'is_pkp' => true
        ]);
        $daizuLocation = Location::factory()->create(['setting_id' => $daizuSetting->id]);

        $product = Product::create([
            'product_name' => 'Kedelai Premium 5kg',
            'product_code' => 'KEDELAI-001',
            'setting_id' => $daizuSetting->id,
            'unit_id' => 1,
            'stock_managed' => 1,
            'product_quantity' => 50,
            'product_cost' => 0,
            'product_price' => 0,
            'is_sold' => 1,
            'is_purchased' => 1,
            'purchase_price' => 0,
            'sale_price' => 0,
            'product_tax_type' => 1
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $daizuLocation->id,
            'quantity' => 50,
            'quantity_tax' => 50,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Create prior ledger transaction
        Transaction::create([
            'product_id' => $product->id,
            'setting_id' => $daizuSetting->id,
            'location_id' => $daizuLocation->id,
            'type' => 'ADJ',
            'quantity' => 50,
            'current_quantity' => 50,
            'previous_quantity' => 0,
            'after_quantity' => 50,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 50,
            'quantity_tax' => 50,
            'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'user_id' => $this->user->id,
            'reason' => 'Initial stock',
        ]);

        $path = $this->createXlsxFile('test_daizu_routing', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['Kedelai Premium 5kg', 'KEDELAI-001', '85,000.00', '120'],
        ]);

        $batch = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $daizuLocation->id,
            'source_csv_path' => $path,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT,
        ]);

        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $batch->refresh();
        $this->assertEquals('completed', $batch->status);
        $this->assertEquals(1, $batch->success_rows);

        // Verify product price updated for DAIZU owner
        $price = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $daizuSetting->id)
            ->first();
        $this->assertEquals(85000, $price->sale_price);

        // Verify stock updated at DAIZU location
        $stock = ProductStock::where('product_id', $product->id)
            ->where('location_id', $daizuLocation->id)
            ->first();
        $this->assertEquals(120, $stock->quantity);
        $this->assertEquals(120, $stock->quantity_tax); // DAIZU is PKP

        // Verify transaction created (latest one)
        $txn = Transaction::where('product_id', $product->id)
            ->where('location_id', $daizuLocation->id)
            ->where('type', 'ADJ')
            ->latest('id')
            ->first();
        $this->assertNotNull($txn);
        $this->assertEquals(70, (int)$txn->quantity); // Delta: 120 - 50
    }

    public function test_it_handles_zero_and_negative_stock()
    {
        $product = Product::create([
            'product_name' => 'Test Product',
            'setting_id' => $this->setting->id,
            'unit_id' => 1,
            'stock_managed' => 1,
            'product_quantity' => 100,
            'product_cost' => 0,
            'product_price' => 0,
            'is_sold' => 1,
            'is_purchased' => 1,
            'purchase_price' => 0,
            'sale_price' => 0,
            'product_tax_type' => 1
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 100,
            'quantity_tax' => 100,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $path = $this->createXlsxFile('test_zero_negative_stock', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['Test Product', '', '100,000.00', '0'],
            ['Test Product', '', '100,000.00', '-25'],
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
        // Should process as conflict (two different stock values for same target)
        $this->assertEquals('completed', $batch->status);
        $this->assertEquals(0, $batch->success_rows);
        $this->assertEquals(2, $batch->error_rows);
    }

    public function test_it_updates_daizu_location_and_tax_bucket()
    {
        // Create two DAIZU-like settings with different company names
        $daizuSetting = Setting::factory()->create([
            'company_name' => 'PT DAIZU',
            'is_pkp' => true
        ]);
        $daizuLocation = Location::factory()->create(['setting_id' => $daizuSetting->id]);

        // Create product with KEDELAI keyword (triggers DAIZU routing)
        $product = Product::create([
            'product_name' => 'Benih Kedelai Unggul',
            'product_code' => 'BENIH-KED-001',
            'setting_id' => $daizuSetting->id,
            'unit_id' => 1,
            'stock_managed' => 1,
            'product_quantity' => 60,
            'product_cost' => 0,
            'product_price' => 0,
            'is_sold' => 1,
            'is_purchased' => 1,
            'purchase_price' => 0,
            'sale_price' => 0,
            'product_tax_type' => 1
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $daizuLocation->id,
            'quantity' => 60,
            'quantity_tax' => 60,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Create prior ledger transaction
        Transaction::create([
            'product_id' => $product->id,
            'setting_id' => $daizuSetting->id,
            'location_id' => $daizuLocation->id,
            'type' => 'ADJ',
            'quantity' => 60,
            'current_quantity' => 60,
            'previous_quantity' => 0,
            'after_quantity' => 60,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 60,
            'quantity_tax' => 60,
            'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'user_id' => $this->user->id,
            'reason' => 'Initial stock',
        ]);

        $path = $this->createXlsxFile('test_daizu_location_pkp', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['Benih Kedelai Unggul', 'BENIH-KED-001', '45,000.00', '150'],
        ]);

        $batch = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $daizuLocation->id,
            'source_csv_path' => $path,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT,
        ]);

        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $batch->refresh();
        $this->assertEquals(1, $batch->success_rows);

        $row = ProductImportRow::where('batch_id', $batch->id)->first();
        $meta = $row->result_metadata;

        // Verify DAIZU setting resolution
        $this->assertEquals($daizuSetting->id, $meta['setting_id']);
        $this->assertEquals($daizuLocation->id, $meta['target_location_id']);
        $this->assertTrue($meta['is_pkp']); // DAIZU is PKP

        // Verify stock buckets
        $this->assertEquals(90, $meta['delta_quantity']); // 150 - 60
        $this->assertEquals(150, $meta['after_quantity_tax']);
        $this->assertEquals(0, $meta['after_quantity_non_tax']);
    }

    public function test_persistence_failure_rolls_back_price_and_stock()
    {
        $product = Product::create([
            'product_name' => 'Keyboard',
            'product_code' => 'SKU-001',
            'product_quantity' => 50,
            'setting_id' => $this->setting->id,
            'unit_id' => 1,
            'stock_managed' => 1,
            'product_cost' => 0,
            'product_price' => 0,
            'is_sold' => 1,
            'is_purchased' => 1,
            'purchase_price' => 0,
            'sale_price' => 0,
            'product_tax_type' => 1
        ]);

        $originalPrice = ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 400000,
            'tier_1_price' => 400000,
            'tier_2_price' => 400000,
        ]);

        $originalStock = ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 50,
            'quantity_tax' => 50,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        Transaction::create([
            'product_id' => $product->id,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'type' => 'ADJ',
            'quantity' => 50,
            'current_quantity' => 50,
            'previous_quantity' => 0,
            'after_quantity' => 50,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 50,
            'quantity_tax' => 50,
            'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'user_id' => $this->user->id,
            'reason' => 'Initial stock',
        ]);

        $path = $this->createXlsxFile('test_persistence_failure', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['* Keyboard', 'SKU-001', '500,000.00', '100'],
        ]);

        $batch = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $this->location->id,
            'source_csv_path' => $path,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT,
        ]);

        // Inject failure via Product event listener that throws during saving quantity
        $saveCount = 0;
        Product::saving(function ($model) use (&$saveCount, $product) {
            if ($model->id === $product->id) {
                $saveCount++;
                if ($saveCount === 1) {
                    throw new \Exception('Simulated database persistence failure');
                }
            }
        });

        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $batch->refresh();
        $this->assertEquals('completed', $batch->status);
        $this->assertEquals(0, $batch->success_rows);
        $this->assertEquals(1, $batch->error_rows);

        // Verify price mutations were rolled back
        $price = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->setting->id)
            ->first();
        $this->assertEquals(400000, $price->sale_price);
        $this->assertEquals(400000, $price->tier_1_price);
        $this->assertEquals(400000, $price->tier_2_price);

        // Verify stock mutations were rolled back
        $stock = ProductStock::where('product_id', $product->id)
            ->where('location_id', $this->location->id)
            ->first();
        $this->assertEquals(50, $stock->quantity);
        $this->assertEquals(50, $stock->quantity_tax);
        $this->assertEquals(0, $stock->quantity_non_tax);

        // Verify product quantity was rolled back
        $product->refresh();
        $this->assertEquals(50, $product->product_quantity);

        // Verify no new Transaction was created
        $txnCount = Transaction::where('product_id', $product->id)
            ->where('type', 'ADJ')
            ->count();
        $this->assertEquals(1, $txnCount);

        // Verify error message is persisted in row
        $row = ProductImportRow::where('batch_id', $batch->id)->first();
        $this->assertStringContainsString('persistence failure', $row->error_message);
    }

    public function test_mixed_outcome_one_fails_one_succeeds()
    {
        // Create two distinct products for independent targets
        $product1 = Product::create([
            'product_name' => 'Keyboard',
            'product_code' => 'SKU-001',
            'product_quantity' => 50,
            'setting_id' => $this->setting->id,
            'unit_id' => 1,
            'stock_managed' => 1,
            'product_cost' => 0,
            'product_price' => 0,
            'is_sold' => 1,
            'is_purchased' => 1,
            'purchase_price' => 0,
            'sale_price' => 0,
            'product_tax_type' => 1
        ]);

        $product2 = Product::create([
            'product_name' => 'Mouse',
            'product_code' => 'SKU-002',
            'product_quantity' => 30,
            'setting_id' => $this->setting->id,
            'unit_id' => 1,
            'stock_managed' => 1,
            'product_cost' => 0,
            'product_price' => 0,
            'is_sold' => 1,
            'is_purchased' => 1,
            'purchase_price' => 0,
            'sale_price' => 0,
            'product_tax_type' => 1
        ]);

        // Setup initial stock and price for both
        ProductPrice::create([
            'product_id' => $product1->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 400000,
            'tier_1_price' => 400000,
            'tier_2_price' => 400000,
        ]);

        ProductStock::create([
            'product_id' => $product1->id,
            'location_id' => $this->location->id,
            'quantity' => 50,
            'quantity_tax' => 50,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        Transaction::create([
            'product_id' => $product1->id,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'type' => 'ADJ',
            'quantity' => 50,
            'current_quantity' => 50,
            'previous_quantity' => 0,
            'after_quantity' => 50,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 50,
            'quantity_tax' => 50,
            'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'user_id' => $this->user->id,
            'reason' => 'Initial stock',
        ]);

        ProductPrice::create([
            'product_id' => $product2->id,
            'setting_id' => $this->setting->id,
            'sale_price' => 200000,
            'tier_1_price' => 200000,
            'tier_2_price' => 200000,
        ]);

        ProductStock::create([
            'product_id' => $product2->id,
            'location_id' => $this->location->id,
            'quantity' => 30,
            'quantity_tax' => 30,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        Transaction::create([
            'product_id' => $product2->id,
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'type' => 'ADJ',
            'quantity' => 30,
            'current_quantity' => 30,
            'previous_quantity' => 0,
            'after_quantity' => 30,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 30,
            'quantity_tax' => 30,
            'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'user_id' => $this->user->id,
            'reason' => 'Initial stock',
        ]);

        $path = $this->createXlsxFile('test_mixed_outcome', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['* Keyboard', 'SKU-001', '500,000.00', '100'],
            ['* Mouse', 'SKU-002', '250,000.00', '75'],
        ]);

        $batch = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $this->location->id,
            'source_csv_path' => $path,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT,
        ]);

        // Inject failure on first product's transaction by throwing on first save
        $failureTriggered = false;
        Product::saving(function ($model) use (&$failureTriggered, $product1) {
            if ($model->id === $product1->id && !$failureTriggered) {
                $failureTriggered = true;
                throw new \Exception('Simulated database persistence failure');
            }
        });

        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $batch->refresh();
        $this->assertEquals('completed', $batch->status);
        $this->assertEquals(1, $batch->success_rows);
        $this->assertEquals(1, $batch->error_rows);

        // Verify product 1 mutations were rolled back
        $price1 = ProductPrice::where('product_id', $product1->id)
            ->where('setting_id', $this->setting->id)
            ->first();
        $this->assertEquals(400000, $price1->sale_price);

        $stock1 = ProductStock::where('product_id', $product1->id)
            ->where('location_id', $this->location->id)
            ->first();
        $this->assertEquals(50, $stock1->quantity);

        $product1->refresh();
        $this->assertEquals(50, $product1->product_quantity);

        // Verify product 2 mutations succeeded
        $price2 = ProductPrice::where('product_id', $product2->id)
            ->where('setting_id', $this->setting->id)
            ->first();
        $this->assertEquals(250000, $price2->sale_price);

        $stock2 = ProductStock::where('product_id', $product2->id)
            ->where('location_id', $this->location->id)
            ->first();
        $this->assertEquals(75, $stock2->quantity);

        $product2->refresh();
        $this->assertEquals(75, $product2->product_quantity);

        // Verify transaction counts
        $txn1Count = Transaction::where('product_id', $product1->id)
            ->where('type', 'ADJ')
            ->count();
        $this->assertEquals(1, $txn1Count);

        $txn2Count = Transaction::where('product_id', $product2->id)
            ->where('type', 'ADJ')
            ->count();
        $this->assertEquals(2, $txn2Count);
    }

    public function test_it_persists_stock_id_in_row_metadata_and_database()
    {
        $product = Product::create([
            'product_name' => 'Keyboard',
            'setting_id' => $this->setting->id,
            'unit_id' => 1,
            'stock_managed' => 1,
            'product_quantity' => 50,
            'product_cost' => 0,
            'product_price' => 0,
            'is_sold' => 1,
            'is_purchased' => 1,
            'purchase_price' => 0,
            'sale_price' => 0,
            'product_tax_type' => 1
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 50,
            'quantity_tax' => 50,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $path = $this->createXlsxFile('test_stock_id_persistence', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['* Keyboard', '', '500,000.00', '100'],
        ]);

        $batch = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $this->location->id,
            'source_csv_path' => $path,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT,
        ]);

        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $row = ProductImportRow::where('batch_id', $batch->id)->first();
        $this->assertNotNull($row->created_stock_id);
        $this->assertNotNull($row->created_txn_id);

        // Verify metadata also contains stock ID
        $meta = $row->result_metadata;
        $this->assertEquals($row->created_stock_id, $meta['created_stock_id']);
    }

    public function test_duplicate_rows_share_same_stock_and_transaction_references()
    {
        $product = Product::create([
            'product_name' => 'Keyboard',
            'setting_id' => $this->setting->id,
            'unit_id' => 1,
            'stock_managed' => 1,
            'product_quantity' => 50,
            'product_cost' => 0,
            'product_price' => 0,
            'is_sold' => 1,
            'is_purchased' => 1,
            'purchase_price' => 0,
            'sale_price' => 0,
            'product_tax_type' => 1
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'quantity' => 50,
            'quantity_tax' => 50,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $path = $this->createXlsxFile('test_duplicate_stock_references', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['* Keyboard', '', '500,000.00', '100'],
            ['* Keyboard', '', '500,000.00', '100'], // Duplicate
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
        $firstRow = $rows[0];
        $secondRow = $rows[1];

        // Both rows should reference same stock and transaction
        $this->assertEquals($firstRow->created_stock_id, $secondRow->created_stock_id);
        $this->assertEquals($firstRow->created_txn_id, $secondRow->created_txn_id);

        // Verify only one stock mutation occurred
        $mutations = Transaction::where('product_id', $product->id)
            ->where('type', 'ADJ')
            ->count();
        $this->assertEquals(1, $mutations);
    }

    public function test_retired_csv_stock_routes_are_disabled()
    {
        // Verify the CSV stock snapshot routes are not available
        // by checking they are commented out in the routes file
        $routesPath = base_path('Modules/Product/Routes/web.php');
        $content = file_get_contents($routesPath);

        // Routes should be commented out (not deleted)
        $this->assertStringContainsString("// Route::get('/products/stock-snapshot/upload'", $content);
        $this->assertStringContainsString("// Route::post('/products/stock-snapshot/upload'", $content);
        $this->assertStringContainsString("// Route::get('/products/stock-snapshot/template'", $content);

        // Verify comments indicate retirement
        $this->assertStringContainsString('(RETIRED:', $content);
    }

    public function test_imports_index_view_no_longer_shows_csv_stock_button()
    {
        // Verify the blade file no longer contains the old CSV stock button
        $viewPath = base_path('Modules/Product/Resources/views/products/imports/index.blade.php');
        $content = file_get_contents($viewPath);

        // Old button text should not be present
        $this->assertStringNotContainsString('Upload Stok Snapshot', $content);
        // New combined label should be present instead
        $this->assertStringContainsString('Upload Harga Jual & Stok Snapshot', $content);
    }

    public function test_batch_detail_describes_combined_price_and_stock_snapshots()
    {
        // Verify the show view file describes combined snapshot
        $viewPath = base_path('Modules/Product/Resources/views/products/imports/show.blade.php');
        $content = file_get_contents($viewPath);
        $this->assertStringContainsString('Harga Jual & Stok Snapshot', $content);
        $this->assertStringContainsString('Imported Stock', $content);
        $this->assertStringContainsString('Imported Price', $content);
    }
}
