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

    public function test_asterisk_marker_updates_only_cv_tiga_nusa()
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

        // Import price with asterisk marker for CV TIGA NUSA only
        $path = $this->createXlsxFile('test_asterisk_marker', [
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

        // Verify owner (CV TIGA NUSA) price was set with all three tiers
        $ownerPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->settings['CV TIGA NUSA COMPUTER']->id)
            ->first();
        $this->assertNotNull($ownerPrice);
        $this->assertEquals(500000, $ownerPrice->sale_price);
        $this->assertEquals(500000, $ownerPrice->tier_1_price);
        $this->assertEquals(500000, $ownerPrice->tier_2_price);

        // Verify non-owner settings do NOT have price rows created
        $topItPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->settings['CV TOP IT INTERNUSA']->id)
            ->first();
        $this->assertNull($topItPrice, "CV TOP IT should not have a price row after import");

        $perdanaPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->settings['PERDANA']->id)
            ->first();
        $this->assertNull($perdanaPrice, "PERDANA should not have a price row after import");

        // Verify stock location isolation: CV TIGA NUSA location has stock
        $tiganusaStock = ProductStock::where('product_id', $product->id)
            ->where('location_id', $this->locations['CV TIGA NUSA COMPUTER']->id)
            ->first();
        $this->assertNotNull($tiganusaStock);
        $this->assertEquals(100, $tiganusaStock->quantity);

        // Verify CV TOP IT location has no stock record
        $topitStock = ProductStock::where('product_id', $product->id)
            ->where('location_id', $this->locations['CV TOP IT INTERNUSA']->id)
            ->first();
        $this->assertNull($topitStock, "CV TOP IT location should not have stock record");

        // Verify PERDANA location has no stock record
        $perdanaStock = ProductStock::where('product_id', $product->id)
            ->where('location_id', $this->locations['PERDANA']->id)
            ->first();
        $this->assertNull($perdanaStock, "PERDANA location should not have stock record");
    }

    public function test_existing_non_owner_prices_remain_unchanged()
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

        // Verify owner price was updated with all three tiers
        $ownerPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->settings['CV TIGA NUSA COMPUTER']->id)
            ->first();
        $this->assertEquals(200000, $ownerPrice->sale_price);
        $this->assertEquals(200000, $ownerPrice->tier_1_price);
        $this->assertEquals(200000, $ownerPrice->tier_2_price);

        // Verify other non-owner settings do NOT get new price rows
        $topItPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->settings['CV TOP IT INTERNUSA']->id)
            ->first();
        $this->assertNull($topItPrice, "CV TOP IT should not have a new price row");
    }

    public function test_top_it_marker_updates_only_cv_top_it()
    {
        // Create product
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

        // Create stock locations for CV TOP IT
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

        // Import with TP marker for CV TOP IT only
        $path = $this->createXlsxFile('test_tp_marker', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['Monitor TP', 'MON-001', '600,000.00', '80'],
        ]);

        $batch = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $this->locations['CV TOP IT INTERNUSA']->id,
            'source_csv_path' => $path,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT,
        ]);

        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $batch->refresh();
        $this->assertEquals('completed', $batch->status);
        $this->assertEquals(1, $batch->success_rows);

        // Verify CV TOP IT price was created with all three tiers
        $topItPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->settings['CV TOP IT INTERNUSA']->id)
            ->first();
        $this->assertNotNull($topItPrice);
        $this->assertEquals(600000, $topItPrice->sale_price);
        $this->assertEquals(600000, $topItPrice->tier_1_price);
        $this->assertEquals(600000, $topItPrice->tier_2_price);

        // Verify CV TIGA NUSA price was NOT created
        $tgPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->settings['CV TIGA NUSA COMPUTER']->id)
            ->first();
        $this->assertNull($tgPrice, "CV TIGA NUSA should not have a price row after TP-marked import");

        // Verify PERDANA price was NOT created
        $perdanaPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->settings['PERDANA']->id)
            ->first();
        $this->assertNull($perdanaPrice, "PERDANA should not have a price row");

        // Verify stock location isolation: CV TOP IT location has stock
        $topitStock = ProductStock::where('product_id', $product->id)
            ->where('location_id', $this->locations['CV TOP IT INTERNUSA']->id)
            ->first();
        $this->assertNotNull($topitStock);
        $this->assertEquals(80, $topitStock->quantity);

        // Verify CV TIGA NUSA location has no stock record
        $tiganusaStock = ProductStock::where('product_id', $product->id)
            ->where('location_id', $this->locations['CV TIGA NUSA COMPUTER']->id)
            ->first();
        $this->assertNull($tiganusaStock, "CV TIGA NUSA location should not have stock record");

        // Verify PERDANA location has no stock record
        $perdanaStock = ProductStock::where('product_id', $product->id)
            ->where('location_id', $this->locations['PERDANA']->id)
            ->first();
        $this->assertNull($perdanaStock, "PERDANA location should not have stock record");
    }

    public function test_unmarked_row_updates_only_perdana()
    {
        // Create product
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

        // Create stock location for PERDANA
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

        // Import with unmarked row for PERDANA only
        $path = $this->createXlsxFile('test_unmarked_row', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['Mouse', 'MSE-001', '350,000.00', '75'],
        ]);

        $batch = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $this->locations['PERDANA']->id,
            'source_csv_path' => $path,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT,
        ]);

        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $batch->refresh();
        $this->assertEquals('completed', $batch->status);
        $this->assertEquals(1, $batch->success_rows);

        // Verify PERDANA price was created with all three tiers
        $perdanaPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->settings['PERDANA']->id)
            ->first();
        $this->assertNotNull($perdanaPrice);
        $this->assertEquals(350000, $perdanaPrice->sale_price);
        $this->assertEquals(350000, $perdanaPrice->tier_1_price);
        $this->assertEquals(350000, $perdanaPrice->tier_2_price);

        // Verify CV TIGA NUSA price was NOT created
        $tgPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->settings['CV TIGA NUSA COMPUTER']->id)
            ->first();
        $this->assertNull($tgPrice, "CV TIGA NUSA should not have a price row after unmarked import");

        // Verify CV TOP IT price was NOT created
        $topItPrice = ProductPrice::where('product_id', $product->id)
            ->where('setting_id', $this->settings['CV TOP IT INTERNUSA']->id)
            ->first();
        $this->assertNull($topItPrice, "CV TOP IT should not have a price row");

        // Verify stock location isolation: PERDANA location has stock
        $perdanaStock = ProductStock::where('product_id', $product->id)
            ->where('location_id', $this->locations['PERDANA']->id)
            ->first();
        $this->assertNotNull($perdanaStock);
        $this->assertEquals(75, $perdanaStock->quantity);

        // Verify CV TIGA NUSA location has no stock record
        $tiganusaStock = ProductStock::where('product_id', $product->id)
            ->where('location_id', $this->locations['CV TIGA NUSA COMPUTER']->id)
            ->first();
        $this->assertNull($tiganusaStock, "CV TIGA NUSA location should not have stock record");

        // Verify CV TOP IT location has no stock record
        $topitStock = ProductStock::where('product_id', $product->id)
            ->where('location_id', $this->locations['CV TOP IT INTERNUSA']->id)
            ->first();
        $this->assertNull($topitStock, "CV TOP IT location should not have stock record");
    }

    public function test_owner_price_update_isolates_only_target_owner()
    {
        // Create two products: one for CV TIGA NUSA, one for CV TOP IT
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

        // Create stock locations
        ProductStock::create([
            'product_id' => $product1->id,
            'location_id' => $this->locations['CV TIGA NUSA COMPUTER']->id,
            'quantity' => 0, 'quantity_tax' => 0, 'quantity_non_tax' => 0,
            'broken_quantity' => 0, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0,
        ]);

        ProductStock::create([
            'product_id' => $product2->id,
            'location_id' => $this->locations['CV TOP IT INTERNUSA']->id,
            'quantity' => 0, 'quantity_tax' => 0, 'quantity_non_tax' => 0,
            'broken_quantity' => 0, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0,
        ]);

        // Import: CV TIGA NUSA updates only its product
        $path1 = $this->createXlsxFile('test_owner_isolation_1', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['* Keyboard', 'SKU-001', '500,000.00', '50'],
        ]);

        $batch1 = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $this->locations['CV TIGA NUSA COMPUTER']->id,
            'source_csv_path' => $path1,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT,
        ]);

        (new ProcessSalesPriceSnapshotBatch($batch1->id))->handle();

        // Verify CV TIGA NUSA product has price
        $p1Price = ProductPrice::where('product_id', $product1->id)
            ->where('setting_id', $this->settings['CV TIGA NUSA COMPUTER']->id)
            ->first();
        $this->assertNotNull($p1Price);
        $this->assertEquals(500000, $p1Price->sale_price);

        // CV TOP IT product should have NO price row (not seeded)
        $p2OwnerPrice = ProductPrice::where('product_id', $product2->id)
            ->where('setting_id', $this->settings['CV TOP IT INTERNUSA']->id)
            ->first();
        $this->assertNull($p2OwnerPrice);

        // Import: CV TOP IT updates only its product
        $path2 = $this->createXlsxFile('test_owner_isolation_2', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['Mouse TP', 'SKU-002', '300,000.00', '75'],
        ]);

        $batch2 = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $this->locations['CV TOP IT INTERNUSA']->id,
            'source_csv_path' => $path2,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT,
        ]);

        (new ProcessSalesPriceSnapshotBatch($batch2->id))->handle();

        // Verify CV TOP IT product now has price
        $p2Price = ProductPrice::where('product_id', $product2->id)
            ->where('setting_id', $this->settings['CV TOP IT INTERNUSA']->id)
            ->first();
        $this->assertNotNull($p2Price);
        $this->assertEquals(300000, $p2Price->sale_price);

        // Verify CV TIGA NUSA product price unchanged
        $p1PriceAfter = ProductPrice::where('product_id', $product1->id)
            ->where('setting_id', $this->settings['CV TIGA NUSA COMPUTER']->id)
            ->first();
        $this->assertNotNull($p1PriceAfter);
        $this->assertEquals(500000, $p1PriceAfter->sale_price);
    }

    public function test_multiple_products_with_owner_isolated_updates()
    {
        // Create two products, each for different owners
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
            'quantity' => 0, 'quantity_tax' => 0, 'quantity_non_tax' => 0,
            'broken_quantity' => 0, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0,
        ]);

        ProductStock::create([
            'product_id' => $product2->id,
            'location_id' => $this->locations['CV TOP IT INTERNUSA']->id,
            'quantity' => 0, 'quantity_tax' => 0, 'quantity_non_tax' => 0,
            'broken_quantity' => 0, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0,
        ]);

        // Import both products in single batch with their respective markers
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

        // Verify product1 (CV TIGA NUSA) price only
        $p1OwnerPrice = ProductPrice::where('product_id', $product1->id)
            ->where('setting_id', $this->settings['CV TIGA NUSA COMPUTER']->id)
            ->first();
        $this->assertNotNull($p1OwnerPrice);
        $this->assertEquals(500000, $p1OwnerPrice->sale_price);
        $this->assertEquals(500000, $p1OwnerPrice->tier_1_price);
        $this->assertEquals(500000, $p1OwnerPrice->tier_2_price);

        // Product1 should NOT have price in other settings
        $p1TopItPrice = ProductPrice::where('product_id', $product1->id)
            ->where('setting_id', $this->settings['CV TOP IT INTERNUSA']->id)
            ->first();
        $this->assertNull($p1TopItPrice);

        $p1PerdanaPrice = ProductPrice::where('product_id', $product1->id)
            ->where('setting_id', $this->settings['PERDANA']->id)
            ->first();
        $this->assertNull($p1PerdanaPrice);

        // Verify product2 (CV TOP IT) price only
        $p2OwnerPrice = ProductPrice::where('product_id', $product2->id)
            ->where('setting_id', $this->settings['CV TOP IT INTERNUSA']->id)
            ->first();
        $this->assertNotNull($p2OwnerPrice);
        $this->assertEquals(200000, $p2OwnerPrice->sale_price);
        $this->assertEquals(200000, $p2OwnerPrice->tier_1_price);
        $this->assertEquals(200000, $p2OwnerPrice->tier_2_price);

        // Product2 should NOT have price in other settings
        $p2TigaNusaPrice = ProductPrice::where('product_id', $product2->id)
            ->where('setting_id', $this->settings['CV TIGA NUSA COMPUTER']->id)
            ->first();
        $this->assertNull($p2TigaNusaPrice);

        $p2PerdanaPrice = ProductPrice::where('product_id', $product2->id)
            ->where('setting_id', $this->settings['PERDANA']->id)
            ->first();
        $this->assertNull($p2PerdanaPrice);
    }

    public function test_owner_isolation_with_daizu_precedence()
    {
        // Create DAIZU setting with a KEDELAI product
        $daizu = Setting::factory()->create(['company_name' => 'DAIZU NUSA']);
        $locationDaizu = Location::factory()->create(['setting_id' => $daizu->id]);

        $productDaizu = Product::create([
            'product_name' => 'KEDELAI SEEDS',
            'product_code' => 'KEDELAI-001',
            'product_quantity' => 0,
            'setting_id' => $daizu->id,
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

        ProductStock::create([
            'product_id' => $productDaizu->id,
            'location_id' => $locationDaizu->id,
            'quantity' => 0, 'quantity_tax' => 0, 'quantity_non_tax' => 0,
            'broken_quantity' => 0, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0,
        ]);

        // Create a regular product for CV TIGA NUSA
        $productRegular = Product::create([
            'product_name' => 'Regular Item',
            'product_code' => 'REG-001',
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

        ProductStock::create([
            'product_id' => $productRegular->id,
            'location_id' => $this->locations['CV TIGA NUSA COMPUTER']->id,
            'quantity' => 0, 'quantity_tax' => 0, 'quantity_non_tax' => 0,
            'broken_quantity' => 0, 'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0,
        ]);

        // Import both: KEDELAI with both asterisk and TP markers (DAIZU takes precedence), regular item with asterisk
        $path = $this->createXlsxFile('test_daizu_precedence', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['* KEDELAI SEEDS', 'KEDELAI-001', '10000', '100'],
            ['KEDELAI SEEDS TP', 'KEDELAI-001', '10000', '100'],
            ['* Regular Item', 'REG-001', '5000', '50'],
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

        // KEDELAI rows (both marked with * and TP) should only update DAIZU, not CV TIGA NUSA/CV TOP IT
        $daizuPrice = ProductPrice::where('product_id', $productDaizu->id)
            ->where('setting_id', $daizu->id)
            ->first();
        $this->assertNotNull($daizuPrice);
        $this->assertEquals(10000, $daizuPrice->sale_price);
        $this->assertEquals(10000, $daizuPrice->tier_1_price);
        $this->assertEquals(10000, $daizuPrice->tier_2_price);

        // KEDELAI should NOT have prices in CV TIGA NUSA or CV TOP IT (DAIZU has precedence)
        $tigaNusaKedelaiPrice = ProductPrice::where('product_id', $productDaizu->id)
            ->where('setting_id', $this->settings['CV TIGA NUSA COMPUTER']->id)
            ->first();
        $this->assertNull($tigaNusaKedelaiPrice);

        $topItKedelaiPrice = ProductPrice::where('product_id', $productDaizu->id)
            ->where('setting_id', $this->settings['CV TOP IT INTERNUSA']->id)
            ->first();
        $this->assertNull($topItKedelaiPrice);

        // Regular Item should update only CV TIGA NUSA
        $regularPrice = ProductPrice::where('product_id', $productRegular->id)
            ->where('setting_id', $this->settings['CV TIGA NUSA COMPUTER']->id)
            ->first();
        $this->assertNotNull($regularPrice);
        $this->assertEquals(5000, $regularPrice->sale_price);
        $this->assertEquals(5000, $regularPrice->tier_1_price);
        $this->assertEquals(5000, $regularPrice->tier_2_price);

        // Regular Item should NOT have prices in other settings
        $topItRegularPrice = ProductPrice::where('product_id', $productRegular->id)
            ->where('setting_id', $this->settings['CV TOP IT INTERNUSA']->id)
            ->first();
        $this->assertNull($topItRegularPrice);

        // Verify DAIZU stock location: DAIZU location has stock
        $daizuStock = ProductStock::where('product_id', $productDaizu->id)
            ->where('location_id', $locationDaizu->id)
            ->first();
        $this->assertNotNull($daizuStock);
        $this->assertEquals(100, $daizuStock->quantity);

        // Verify DAIZU product has no stock in CV TIGA NUSA or CV TOP IT locations
        $tigaNusaDaizuStock = ProductStock::where('product_id', $productDaizu->id)
            ->where('location_id', $this->locations['CV TIGA NUSA COMPUTER']->id)
            ->first();
        $this->assertNull($tigaNusaDaizuStock, "CV TIGA NUSA location should not have DAIZU product stock");

        $topItDaizuStock = ProductStock::where('product_id', $productDaizu->id)
            ->where('location_id', $this->locations['CV TOP IT INTERNUSA']->id)
            ->first();
        $this->assertNull($topItDaizuStock, "CV TOP IT location should not have DAIZU product stock");

        $perdanaDaizuStock = ProductStock::where('product_id', $productDaizu->id)
            ->where('location_id', $this->locations['PERDANA']->id)
            ->first();
        $this->assertNull($perdanaDaizuStock, "PERDANA location should not have DAIZU product stock");

        // Verify Regular Item stock location: CV TIGA NUSA location has stock
        $tiganusaRegularStock = ProductStock::where('product_id', $productRegular->id)
            ->where('location_id', $this->locations['CV TIGA NUSA COMPUTER']->id)
            ->first();
        $this->assertNotNull($tiganusaRegularStock);
        $this->assertEquals(50, $tiganusaRegularStock->quantity);

        // Verify Regular Item has no stock in other locations
        $topItRegularStock = ProductStock::where('product_id', $productRegular->id)
            ->where('location_id', $this->locations['CV TOP IT INTERNUSA']->id)
            ->first();
        $this->assertNull($topItRegularStock, "CV TOP IT location should not have regular product stock");

        $perdanaRegularStock = ProductStock::where('product_id', $productRegular->id)
            ->where('location_id', $this->locations['PERDANA']->id)
            ->first();
        $this->assertNull($perdanaRegularStock, "PERDANA location should not have regular product stock");
    }
}
