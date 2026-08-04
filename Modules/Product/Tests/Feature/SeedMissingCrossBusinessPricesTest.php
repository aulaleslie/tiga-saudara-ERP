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

class SeedMissingCrossBusinessPricesTest extends TestCase
{
    use RefreshDatabase;

    private $user;
    private $locations;
    private $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Create five settings
        $this->settings = [];
        $settingNames = [
            'CV TIGA NUSA COMPUTER',
            'CV TOP IT INTERNUSA',
            'PERDANA',
            'TIGA COMPUTER',
            'WHITE KNIGHT'
        ];

        foreach ($settingNames as $name) {
            $setting = Setting::factory()->create([
                'company_name' => $name,
                'is_pkp' => true
            ]);
            $this->settings[$name] = $setting;
            $this->locations[$name] = Location::factory()->create(['setting_id' => $setting->id]);
        }
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

    public function test_first_owner_row_seeds_all_missing_settings()
    {
        // Create product
        $product = Product::create([
            'product_name' => 'Keyboard',
            'product_code' => 'SKU-001',
            'product_quantity' => 0,
            'setting_id' => $this->settings['CV TIGA NUSA COMPUTER']->id,
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

        // Import price for CV TIGA NUSA
        $path = $this->createXlsxFile('test_seed_all_missing', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['* Keyboard', 'SKU-001', '500,000.00', '100'],
        ]);

        $batch = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $this->locations['CV TIGA NUSA COMPUTER']->id,
            'source_csv_path' => $path,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT,
        ]);

        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $batch->refresh();
        $this->assertEquals('completed', $batch->status);
        $this->assertEquals(1, $batch->success_rows);

        // Verify owner price was set
        $ownerPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->settings['CV TIGA NUSA COMPUTER']->id)
            ->first();
        $this->assertNotNull($ownerPrice);
        $this->assertEquals(500000, $ownerPrice->sale_price);

        // Verify missing prices were seeded in all other settings (except CV TOP IT INTERNUSA which doesn't have a location)
        foreach ($this->settings as $name => $setting) {
            if ($name === 'CV TIGA NUSA COMPUTER' || !isset($this->locations[$name])) {
                continue;
            }

            $price = ProductPrice::where('product_id', $product->id)
                ->where('setting_id', $setting->id)
                ->first();
            $this->assertNotNull($price, "Price should be seeded for setting: {$name}");
            $this->assertEquals(500000, $price->sale_price, "Seed price for {$name} should be 500000");
            $this->assertEquals(500000, $price->tier_1_price, "Tier 1 price for {$name} should be 500000");
            $this->assertEquals(500000, $price->tier_2_price, "Tier 2 price for {$name} should be 500000");
        }
    }

    public function test_preserves_existing_other_setting_prices()
    {
        // Create product
        $product = Product::create([
            'product_name' => 'Mouse',
            'product_code' => 'SKU-002',
            'product_quantity' => 0,
            'setting_id' => $this->settings['CV TIGA NUSA COMPUTER']->id,
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

        // Pre-existing price for PERDANA
        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->settings['PERDANA']->id,
            'sale_price' => 150000,
            'tier_1_price' => 145000,
            'tier_2_price' => 140000,
        ]);

        // Import price for CV TIGA NUSA
        $path = $this->createXlsxFile('test_preserve_existing', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['* Mouse', 'SKU-002', '200,000.00', '50'],
        ]);

        $batch = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $this->locations['CV TIGA NUSA COMPUTER']->id,
            'source_csv_path' => $path,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT,
        ]);

        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        // Verify PERDANA price was NOT changed
        $perdanaPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->settings['PERDANA']->id)
            ->first();
        $this->assertEquals(150000, $perdanaPrice->sale_price);
        $this->assertEquals(145000, $perdanaPrice->tier_1_price);
        $this->assertEquals(140000, $perdanaPrice->tier_2_price);

        // Verify owner price was updated
        $ownerPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->settings['CV TIGA NUSA COMPUTER']->id)
            ->first();
        $this->assertEquals(200000, $ownerPrice->sale_price);

        // Verify other missing settings were seeded
        $topItPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->settings['CV TOP IT INTERNUSA']->id)
            ->first();
        $this->assertNotNull($topItPrice);
        $this->assertEquals(200000, $topItPrice->sale_price);
    }

    public function test_sequential_owner_updates_only_their_price()
    {
        // This test verifies that a seeded price row can be updated by a later owner-specific import
        // First: CV TIGA NUSA seeds product across all settings
        // Then: CV TOP IT (which received a seeded price) updates its copy with a new value

        $product = Product::create([
            'product_name' => 'Monitor',
            'product_code' => 'MON-001',
            'product_quantity' => 0,
            'setting_id' => $this->settings['CV TIGA NUSA COMPUTER']->id,
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

        // Create stock locations for both CV TIGA NUSA and CV TOP IT
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->locations['CV TIGA NUSA COMPUTER']->id,
            'quantity' => 0,
            'quantity_tax' => 0,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->locations['CV TOP IT INTERNUSA']->id,
            'quantity' => 0,
            'quantity_tax' => 0,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // First import: CV TIGA NUSA seeds the product price across all settings
        $path1 = $this->createXlsxFile('test_sequential_1', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['* Monitor', 'MON-001', '500,000.00', '100'],
        ]);

        $batch1 = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $this->locations['CV TIGA NUSA COMPUTER']->id,
            'source_csv_path' => $path1,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT,
        ]);

        (new ProcessSalesPriceSnapshotBatch($batch1->id))->handle();

        $batch1->refresh();
        $this->assertEquals('completed', $batch1->status);
        $this->assertEquals(1, $batch1->success_rows);

        // Verify all settings have the seeded price
        $topItPriceBefore = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->settings['CV TOP IT INTERNUSA']->id)
            ->first();
        $this->assertNotNull($topItPriceBefore, "CV TOP IT should have seeded price after first import");
        $this->assertEquals(500000, $topItPriceBefore->sale_price);

        // Second import: CV TOP IT updates its own price (which was previously seeded)
        $path2 = $this->createXlsxFile('test_sequential_2', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['Monitor TP', 'MON-001', '600,000.00', '80'],
        ]);

        $batch2 = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $this->locations['CV TOP IT INTERNUSA']->id,
            'source_csv_path' => $path2,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT,
        ]);

        (new ProcessSalesPriceSnapshotBatch($batch2->id))->handle();

        $batch2->refresh();
        $this->assertEquals('completed', $batch2->status);
        $this->assertEquals(1, $batch2->success_rows);
        $this->assertEquals(0, $batch2->error_rows);

        // Verify CV TIGA NUSA price unchanged
        $tgPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->settings['CV TIGA NUSA COMPUTER']->id)
            ->first();
        $this->assertNotNull($tgPrice);
        $this->assertEquals(500000, $tgPrice->sale_price);

        // Verify CV TOP IT price updated
        $topItPriceAfter = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->settings['CV TOP IT INTERNUSA']->id)
            ->first();
        $this->assertNotNull($topItPriceAfter);
        $this->assertEquals(600000, $topItPriceAfter->sale_price);

        // Verify other settings still have seeded price
        $perdanaPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->settings['PERDANA']->id)
            ->first();
        $this->assertNotNull($perdanaPrice);
        $this->assertEquals(500000, $perdanaPrice->sale_price);
    }

    public function test_cross_setting_seeding_rolls_back_on_persistence_failure()
    {
        // Create product with existing owner price row
        $product = Product::create([
            'product_name' => 'Monitor',
            'product_code' => 'SKU-003',
            'product_quantity' => 50,
            'setting_id' => $this->settings['CV TIGA NUSA COMPUTER']->id,
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

        // Create existing owner price row
        $ownerPrice = ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $this->settings['CV TIGA NUSA COMPUTER']->id,
            'sale_price' => 1000000,
            'tier_1_price' => 950000,
            'tier_2_price' => 900000,
            'last_purchase_price' => 0,
            'average_purchase_price' => 0,
        ]);

        // Create initial stock at CV TIGA NUSA location
        $stock = ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->locations['CV TIGA NUSA COMPUTER']->id,
            'quantity' => 50,
            'quantity_tax' => 50,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Create initial ADJ transaction
        $initialTxn = Transaction::create([
            'product_id' => $product->id,
            'setting_id' => $this->settings['CV TIGA NUSA COMPUTER']->id,
            'location_id' => $this->locations['CV TIGA NUSA COMPUTER']->id,
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

        $initialTxnCount = Transaction::count();

        // Set up controlled exception during ProductPrice creation for PERDANA (non-owner setting)
        ProductPrice::creating(function ($model) {
            if ($model->setting_id === $this->settings['PERDANA']->id) {
                throw new \Exception('Test-controlled persistence failure during cross-setting seeding');
            }
        });

        // Import price for CV TIGA NUSA - this should seed all other settings but fail midway
        $path = $this->createXlsxFile('test_failure_scenario', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['* Monitor', 'SKU-003', '5,000,000.00', '100'],
        ]);

        $batch = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $this->locations['CV TIGA NUSA COMPUTER']->id,
            'source_csv_path' => $path,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT,
        ]);

        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $batch->refresh();

        // Row should be marked as error
        $row = ProductImportRow::where('batch_id', $batch->id)->first();
        $this->assertEquals('error', $row->status);
        $this->assertStringContainsString('persistence failure', $row->error_message);

        // Batch counts: 1 error, 0 success
        $this->assertEquals(1, $batch->error_rows);
        $this->assertEquals(0, $batch->success_rows);

        // Owner price should remain unchanged
        $ownerPrice->refresh();
        $this->assertEquals(1000000, $ownerPrice->sale_price);
        $this->assertEquals(950000, $ownerPrice->tier_1_price);
        $this->assertEquals(900000, $ownerPrice->tier_2_price);

        // No missing-setting price rows should have been persisted
        foreach ($this->settings as $name => $setting) {
            if ($name === 'CV TIGA NUSA COMPUTER') {
                continue;
            }
            $price = ProductPrice::where('product_id', $product->id)
                ->where('setting_id', $setting->id)
                ->first();
            $this->assertNull($price, "No cross-setting price should exist for {$name}");
        }

        // Stock quantities should remain unchanged
        $stock->refresh();
        $this->assertEquals(50, $stock->quantity);
        $this->assertEquals(50, $stock->quantity_tax);
        $this->assertEquals(0, $stock->quantity_non_tax);

        // Product aggregate quantity should remain unchanged
        $product->refresh();
        $this->assertEquals(50, $product->product_quantity);

        // No additional ADJ transaction should have been created
        $this->assertEquals($initialTxnCount, Transaction::count());
    }

    public function test_perdana_follow_up_row_updates_only_perdana_price()
    {
        // This test verifies that a follow-up unmarked (Perdana) row updates only Perdana's price
        // First: CV TIGA NUSA seeds product across all settings
        // Then: Perdana (which received a seeded price) updates its copy with a new value

        $product = Product::create([
            'product_name' => 'Mouse',
            'product_code' => 'MSE-001',
            'product_quantity' => 0,
            'setting_id' => $this->settings['CV TIGA NUSA COMPUTER']->id,
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

        // Create stock locations for both CV TIGA NUSA and PERDANA
        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->locations['CV TIGA NUSA COMPUTER']->id,
            'quantity' => 0,
            'quantity_tax' => 0,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $this->locations['PERDANA']->id,
            'quantity' => 0,
            'quantity_tax' => 0,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // First import: CV TIGA NUSA seeds the product price across all settings
        $path1 = $this->createXlsxFile('test_perdana_sequential_1', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['* Mouse', 'MSE-001', '300,000.00', '50'],
        ]);

        $batch1 = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $this->locations['CV TIGA NUSA COMPUTER']->id,
            'source_csv_path' => $path1,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT,
        ]);

        (new ProcessSalesPriceSnapshotBatch($batch1->id))->handle();

        $batch1->refresh();
        $this->assertEquals('completed', $batch1->status);
        $this->assertEquals(1, $batch1->success_rows);

        // Verify PERDANA has the seeded price
        $perdanaPriceBefore = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->settings['PERDANA']->id)
            ->first();
        $this->assertNotNull($perdanaPriceBefore);
        $this->assertEquals(300000, $perdanaPriceBefore->sale_price);

        // Second import: PERDANA updates its own price (unmarked row = default to PERDANA)
        $path2 = $this->createXlsxFile('test_perdana_sequential_2', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['Mouse', 'MSE-001', '350,000.00', '60'],
        ]);

        $batch2 = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $this->locations['PERDANA']->id,
            'source_csv_path' => $path2,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT,
        ]);

        (new ProcessSalesPriceSnapshotBatch($batch2->id))->handle();

        $batch2->refresh();
        $this->assertEquals('completed', $batch2->status);
        $this->assertEquals(1, $batch2->success_rows);
        $this->assertEquals(0, $batch2->error_rows);

        // Verify CV TIGA NUSA price unchanged
        $tgPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->settings['CV TIGA NUSA COMPUTER']->id)
            ->first();
        $this->assertNotNull($tgPrice);
        $this->assertEquals(300000, $tgPrice->sale_price);

        // Verify PERDANA price updated
        $perdanaPriceAfter = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->settings['PERDANA']->id)
            ->first();
        $this->assertNotNull($perdanaPriceAfter);
        $this->assertEquals(350000, $perdanaPriceAfter->sale_price);

        // Verify other settings still have seeded price
        $topItPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->settings['CV TOP IT INTERNUSA']->id)
            ->first();
        $this->assertNotNull($topItPrice);
        $this->assertEquals(300000, $topItPrice->sale_price);
    }

    public function test_multiple_products_with_independent_cross_setting_seeding()
    {
        // Create two products
        $product1 = Product::create([
            'product_name' => 'Keyboard',
            'product_code' => 'SKU-001',
            'product_quantity' => 0,
            'setting_id' => $this->settings['CV TIGA NUSA COMPUTER']->id,
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
            'product_quantity' => 0,
            'setting_id' => $this->settings['CV TOP IT INTERNUSA']->id,
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

        // Create stock locations for both
        ProductStock::create([
            'product_id' => $product1->id,
            'location_id' => $this->locations['CV TIGA NUSA COMPUTER']->id,
            'quantity' => 50,
            'quantity_tax' => 50,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        ProductStock::create([
            'product_id' => $product2->id,
            'location_id' => $this->locations['CV TOP IT INTERNUSA']->id,
            'quantity' => 30,
            'quantity_tax' => 30,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        // Import both products in single batch
        $path = $this->createXlsxFile('test_multiple_products', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['* Keyboard', 'SKU-001', '500,000.00', '100'],
            ['Mouse TP', 'SKU-002', '200,000.00', '80'],
        ]);

        $batch = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $this->locations['CV TIGA NUSA COMPUTER']->id,
            'source_csv_path' => $path,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT,
        ]);

        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $batch->refresh();

        // Both rows should be imported successfully
        $this->assertEquals('completed', $batch->status);
        $this->assertEquals(2, $batch->success_rows);
        $this->assertEquals(0, $batch->error_rows);

        // Verify product1 (CV TIGA NUSA) price and seeding
        $p1OwnerPrice = ProductPrice::where('product_id', $product1->id)
            ->where('setting_id', $this->settings['CV TIGA NUSA COMPUTER']->id)
            ->first();
        $this->assertNotNull($p1OwnerPrice);
        $this->assertEquals(500000, $p1OwnerPrice->sale_price);

        $p1OtherPrice = ProductPrice::where('product_id', $product1->id)
            ->where('setting_id', $this->settings['CV TOP IT INTERNUSA']->id)
            ->first();
        $this->assertNotNull($p1OtherPrice);
        $this->assertEquals(500000, $p1OtherPrice->sale_price);

        // Verify product2 (CV TOP IT) price and seeding
        $p2OwnerPrice = ProductPrice::where('product_id', $product2->id)
            ->where('setting_id', $this->settings['CV TOP IT INTERNUSA']->id)
            ->first();
        $this->assertNotNull($p2OwnerPrice);
        $this->assertEquals(200000, $p2OwnerPrice->sale_price);

        $p2OtherPrice = ProductPrice::where('product_id', $product2->id)
            ->where('setting_id', $this->settings['CV TIGA NUSA COMPUTER']->id)
            ->first();
        $this->assertNotNull($p2OtherPrice);
        $this->assertEquals(200000, $p2OtherPrice->sale_price);

        // Verify all other settings have seeded prices for both products
        foreach ($this->settings as $name => $setting) {
            if ($name === 'CV TIGA NUSA COMPUTER') {
                continue;
            }

            $p1Price = ProductPrice::where('product_id', $product1->id)
                ->where('setting_id', $setting->id)
                ->first();
            if ($name !== 'CV TOP IT INTERNUSA') {
                $this->assertNotNull($p1Price, "Product1 should have price in {$name}");
                $this->assertEquals(500000, $p1Price->sale_price);
            }
        }

        foreach ($this->settings as $name => $setting) {
            if ($name === 'CV TOP IT INTERNUSA') {
                continue;
            }

            $p2Price = ProductPrice::where('product_id', $product2->id)
                ->where('setting_id', $setting->id)
                ->first();
            if ($name !== 'CV TIGA NUSA COMPUTER') {
                $this->assertNotNull($p2Price, "Product2 should have price in {$name}");
                $this->assertEquals(200000, $p2Price->sale_price);
            }
        }
    }

    public function test_mixed_outcome_one_fails_one_succeeds_in_same_batch()
    {
        // Product A: owned by CV TIGA NUSA with existing owner price, stock, and ADJ transaction
        $productA = Product::create([
            'product_name' => 'Keyboard',
            'product_code' => 'KBD-001',
            'product_quantity' => 75,
            'setting_id' => $this->settings['CV TIGA NUSA COMPUTER']->id,
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

        $priceA = ProductPrice::create([
            'product_id' => $productA->id,
            'setting_id' => $this->settings['CV TIGA NUSA COMPUTER']->id,
            'sale_price' => 400000,
            'tier_1_price' => 380000,
            'tier_2_price' => 360000,
            'last_purchase_price' => 0,
            'average_purchase_price' => 0,
        ]);

        $stockA = ProductStock::create([
            'product_id' => $productA->id,
            'location_id' => $this->locations['CV TIGA NUSA COMPUTER']->id,
            'quantity' => 75,
            'quantity_tax' => 75,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $txnA = Transaction::create([
            'product_id' => $productA->id,
            'setting_id' => $this->settings['CV TIGA NUSA COMPUTER']->id,
            'location_id' => $this->locations['CV TIGA NUSA COMPUTER']->id,
            'type' => 'ADJ',
            'quantity' => 75,
            'current_quantity' => 75,
            'previous_quantity' => 0,
            'after_quantity' => 75,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 75,
            'quantity_tax' => 75,
            'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'user_id' => $this->user->id,
            'reason' => 'Initial stock',
        ]);

        $initialTxnCountA = Transaction::where('product_id', $productA->id)->count();

        // Product B: owned by CV TOP IT INTERNUSA with existing owner price, stock, and ADJ transaction
        $productB = Product::create([
            'product_name' => 'Monitor',
            'product_code' => 'MON-002',
            'product_quantity' => 40,
            'setting_id' => $this->settings['CV TOP IT INTERNUSA']->id,
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

        $priceB = ProductPrice::create([
            'product_id' => $productB->id,
            'setting_id' => $this->settings['CV TOP IT INTERNUSA']->id,
            'sale_price' => 2000000,
            'tier_1_price' => 1900000,
            'tier_2_price' => 1800000,
            'last_purchase_price' => 0,
            'average_purchase_price' => 0,
        ]);

        $stockB = ProductStock::create([
            'product_id' => $productB->id,
            'location_id' => $this->locations['CV TOP IT INTERNUSA']->id,
            'quantity' => 40,
            'quantity_tax' => 40,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ]);

        $txnB = Transaction::create([
            'product_id' => $productB->id,
            'setting_id' => $this->settings['CV TOP IT INTERNUSA']->id,
            'location_id' => $this->locations['CV TOP IT INTERNUSA']->id,
            'type' => 'ADJ',
            'quantity' => 40,
            'current_quantity' => 40,
            'previous_quantity' => 0,
            'after_quantity' => 40,
            'previous_quantity_at_location' => 0,
            'after_quantity_at_location' => 40,
            'quantity_tax' => 40,
            'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'user_id' => $this->user->id,
            'reason' => 'Initial stock',
        ]);

        $initialTxnCountB = Transaction::where('product_id', $productB->id)->count();

        // Inject exception for Product A on TIGA COMPUTER setting only
        // This will cause Product A seeding to fail, but leave Product B unaffected
        ProductPrice::creating(function ($model) use ($productA) {
            if (
                $model->product_id === $productA->id &&
                $model->setting_id === $this->settings['TIGA COMPUTER']->id
            ) {
                throw new \Exception('Test-controlled failure during Product A cross-setting seeding to TIGA COMPUTER');
            }
        });

        // Create batch with two rows
        $path = $this->createXlsxFile('test_mixed_outcome', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['* Keyboard', 'KBD-001', '550,000.00', '120'],
            ['Monitor TP', 'MON-002', '2,500,000.00', '60'],
        ]);

        $batch = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $this->locations['CV TIGA NUSA COMPUTER']->id,
            'source_csv_path' => $path,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT,
        ]);

        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $batch->refresh();

        // Batch should complete with 1 success and 1 error
        $this->assertEquals('completed', $batch->status);
        $this->assertEquals(1, $batch->success_rows);
        $this->assertEquals(1, $batch->error_rows);

        // Product A's row should be error
        $rowA = ProductImportRow::where('batch_id', $batch->id)
            ->where('product_id', $productA->id)
            ->first();
        $this->assertNotNull($rowA);
        $this->assertEquals('error', $rowA->status);
        $this->assertStringContainsString('failure', $rowA->error_message);

        // Product B's row should be imported
        $rowB = ProductImportRow::where('batch_id', $batch->id)
            ->where('product_id', $productB->id)
            ->first();
        $this->assertNotNull($rowB);
        $this->assertEquals('imported', $rowB->status);

        // ========== ASSERT PRODUCT A FULLY ROLLED BACK ==========

        // Owner price remains unchanged
        $priceA->refresh();
        $this->assertEquals(400000, $priceA->sale_price);
        $this->assertEquals(380000, $priceA->tier_1_price);
        $this->assertEquals(360000, $priceA->tier_2_price);

        // No newly seeded cross-setting price rows for Product A
        foreach ($this->settings as $name => $setting) {
            if ($name === 'CV TIGA NUSA COMPUTER') {
                continue;
            }
            $price = ProductPrice::where('product_id', $productA->id)
                ->where('setting_id', $setting->id)
                ->first();
            $this->assertNull($price, "Product A should have no cross-setting price row in {$name}");
        }

        // Owner-location stock unchanged
        $stockA->refresh();
        $this->assertEquals(75, $stockA->quantity);
        $this->assertEquals(75, $stockA->quantity_tax);
        $this->assertEquals(0, $stockA->quantity_non_tax);

        // Aggregate product_quantity unchanged
        $productA->refresh();
        $this->assertEquals(75, $productA->product_quantity);

        // No additional ADJ transaction for Product A
        $this->assertEquals($initialTxnCountA, Transaction::where('product_id', $productA->id)->count());

        // ========== ASSERT PRODUCT B FULLY COMPLETED ==========

        // Owner price updated to imported price
        $priceB->refresh();
        $this->assertEquals(2500000, $priceB->sale_price);
        $this->assertEquals(2500000, $priceB->tier_1_price);
        $this->assertEquals(2500000, $priceB->tier_2_price);

        // Every missing price row across all available settings is seeded
        foreach ($this->settings as $name => $setting) {
            if ($name === 'CV TOP IT INTERNUSA') {
                continue;
            }

            $price = ProductPrice::where('product_id', $productB->id)
                ->where('setting_id', $setting->id)
                ->first();
            $this->assertNotNull($price, "Product B should have seeded price in {$name}");
            $this->assertEquals(2500000, $price->sale_price);
            $this->assertEquals(2500000, $price->tier_1_price);
            $this->assertEquals(2500000, $price->tier_2_price);
        }

        // Owner-location stock updated with correct tax/non-tax buckets
        $stockB->refresh();
        $this->assertEquals(60, $stockB->quantity);
        $this->assertEquals(60, $stockB->quantity_tax);
        $this->assertEquals(0, $stockB->quantity_non_tax);

        // Aggregate product_quantity updated
        $productB->refresh();
        $this->assertEquals(60, $productB->product_quantity);

        // Exactly one new ADJ transaction for Product B
        $txnCountB = Transaction::where('product_id', $productB->id)->count();
        $this->assertEquals($initialTxnCountB + 1, $txnCountB);
        $newTxnB = Transaction::where('product_id', $productB->id)
            ->where('setting_id', $this->settings['CV TOP IT INTERNUSA']->id)
            ->where('location_id', $this->locations['CV TOP IT INTERNUSA']->id)
            ->where('type', 'ADJ')
            ->latest('id')
            ->first();
        $this->assertNotNull($newTxnB);
        $this->assertStringContainsString('snapshot import', strtolower($newTxnB->reason));
    }
}
