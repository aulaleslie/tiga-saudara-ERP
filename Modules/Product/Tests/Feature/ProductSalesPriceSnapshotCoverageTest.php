<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductImportBatch;
use Modules\Product\Entities\ProductImportRow;
use Modules\Product\Entities\ProductPrice;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Location;
use Tests\TestCase;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Modules\Product\Jobs\ProcessSalesPriceSnapshotBatch;

class ProductSalesPriceSnapshotCoverageTest extends TestCase
{
    use RefreshDatabase;

    private $user;
    private $location;
    private $setting;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->setting = Setting::factory()->create(['company_name' => 'CV TIGA NUSA COMPUTER']);
        $this->location = Location::factory()->create(['setting_id' => $this->setting->id]);
        $this->user = User::factory()->create();
    }

    private function createXlsxFile(string $name, array $data): string
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        foreach ($data as $rowIndex => $rowData) {
            foreach ($rowData as $colIndex => $value) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex + 1);
                $sheet->setCellValue($colLetter . ($rowIndex + 1), $value);
            }
        }
        
        $path = 'imports/products/' . $name . '.xlsx';
        $fullPath = storage_path('app/' . $path);
        
        if (!is_dir(dirname($fullPath))) {
            mkdir(dirname($fullPath), 0777, true);
        }
        
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($fullPath);
        
        return $path;
    }

    public function test_zero_price_preservation()
    {
        // Should skip zero/blank prices instead of zeroing out existing prices
        $productId = 1;
        \Illuminate\Support\Facades\DB::table('products')->insert([
            'id' => $productId, 'product_code' => 'SKU-ZERO', 'product_name' => 'Zero Item', 'setting_id' => $this->setting->id,
            'unit_id' => 1, 'stock_managed' => 1, 'product_quantity' => 0, 'product_cost' => 0, 'product_price' => 0, 'is_sold' => 1, 'is_purchased' => 1, 'purchase_price' => 0, 'sale_price' => 0, 'product_tax_type' => 1
        ]);
        \Illuminate\Support\Facades\DB::table('product_prices')->insert([
            'product_id' => $productId, 'setting_id' => $this->setting->id, 'sale_price' => 5000, 'tier_1_price' => 5000, 'tier_2_price' => 5000, 'last_purchase_price' => 0, 'created_at' => now(), 'updated_at' => now()
        ]);

        $path = $this->createXlsxFile('test_zero_price', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['* Zero Item', 'SKU-ZERO', '0', '10'],
            ['* Zero Item', 'SKU-ZERO', '', '10'],
        ]);

        $batch = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $this->location->id,
            'source_csv_path' => $path,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT,
        ]);

        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $rows = ProductImportRow::where('batch_id', $batch->id)->get();
        $this->assertCount(2, $rows);
        $this->assertEquals('skipped', $rows[0]->status);
        $this->assertEquals('skipped', $rows[1]->status);
        
        $productId = $productId ?? 1;
        
        $price = ProductPrice::where('product_id', $productId)->first();
        $this->assertEquals(5000, $price->sale_price); // Price preserved
    }

    public function test_maximum_price_rejection()
    {
        \Illuminate\Support\Facades\DB::table('products')->insert([
            'id' => 2, 'product_code' => 'SKU-MAX', 'product_name' => 'Max Item', 'setting_id' => $this->setting->id,
            'unit_id' => 1, 'stock_managed' => 1, 'product_quantity' => 0, 'product_cost' => 0, 'product_price' => 0, 'is_sold' => 1, 'is_purchased' => 1, 'purchase_price' => 0, 'sale_price' => 0, 'product_tax_type' => 1
        ]);

        $path = $this->createXlsxFile('test_max_price', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['* Max Item', 'SKU-MAX', '100000000', '10'], // 100M (too large)
        ]);

        $batch = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $this->location->id,
            'source_csv_path' => $path,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT,
        ]);

        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $rows = ProductImportRow::where('batch_id', $batch->id)->get();
        $this->assertEquals('error', $rows[0]->status);
        $this->assertStringContainsString('Sell price exceeds maximum limit', $rows[0]->error_message);
    }

    public function test_code_name_disagreement()
    {
        // Code is correct, but name belongs to another product
        \Illuminate\Support\Facades\DB::table('products')->insert([
            ['id' => 3, 'product_code' => 'SKU-A', 'product_name' => 'Item A', 'setting_id' => $this->setting->id, 'unit_id' => 1, 'stock_managed' => 1, 'product_quantity' => 0, 'product_cost' => 0, 'product_price' => 0, 'is_sold' => 1, 'is_purchased' => 1, 'purchase_price' => 0, 'sale_price' => 0, 'product_tax_type' => 1],
            ['id' => 4, 'product_code' => 'SKU-B', 'product_name' => 'Item B', 'setting_id' => $this->setting->id, 'unit_id' => 1, 'stock_managed' => 1, 'product_quantity' => 0, 'product_cost' => 0, 'product_price' => 0, 'is_sold' => 1, 'is_purchased' => 1, 'purchase_price' => 0, 'sale_price' => 0, 'product_tax_type' => 1],
        ]);

        $path = $this->createXlsxFile('test_disagreement', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['* Item B', 'SKU-A', '1000', '10'],
        ]);

        $batch = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $this->location->id,
            'source_csv_path' => $path,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT,
        ]);

        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $rows = ProductImportRow::where('batch_id', $batch->id)->get();
        $this->assertEquals('error', $rows[0]->status);
        $this->assertStringContainsString('Code/name disagreement', $rows[0]->error_message);
    }

    public function test_canonical_name_collisions()
    {
        // When there are multiple products with the same normalized name, it should flag ambiguity
        \Illuminate\Support\Facades\DB::table('products')->insert([
            ['id' => 5, 'product_code' => 'SKU-C1', 'product_name' => 'APPLE JUICE', 'setting_id' => $this->setting->id, 'unit_id' => 1, 'stock_managed' => 1, 'product_quantity' => 0, 'product_cost' => 0, 'product_price' => 0, 'is_sold' => 1, 'is_purchased' => 1, 'purchase_price' => 0, 'sale_price' => 0, 'product_tax_type' => 1],
            ['id' => 6, 'product_code' => 'SKU-C2', 'product_name' => 'Apple Juice', 'setting_id' => $this->setting->id, 'unit_id' => 1, 'stock_managed' => 1, 'product_quantity' => 0, 'product_cost' => 0, 'product_price' => 0, 'is_sold' => 1, 'is_purchased' => 1, 'purchase_price' => 0, 'sale_price' => 0, 'product_tax_type' => 1],
        ]);

        $path = $this->createXlsxFile('test_collision', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['* Apple Juice', '', '1000', '10'], // Missing code, relying on name
        ]);

        $batch = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $this->location->id,
            'source_csv_path' => $path,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT,
        ]);

        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $rows = ProductImportRow::where('batch_id', $batch->id)->get();
        $this->assertEquals('error', $rows[0]->status);
        $this->assertStringContainsString('Ambiguous product name', $rows[0]->error_message);
    }

    public function test_create_versus_edit_permission_behavior()
    {
        $userCreate = User::factory()->create();
        $userEdit = User::factory()->create();
        
        session(['setting_id' => $this->setting->id]);
        $this->withoutMiddleware(['role.setting']);
        
        \Illuminate\Support\Facades\Gate::before(function ($user, $ability) use ($userCreate, $userEdit) {
            if ($user->id === $userCreate->id) {
                return in_array($ability, ['products.create', 'products.access']);
            }
            if ($user->id === $userEdit->id) {
                return in_array($ability, ['products.edit', 'products.access']);
            }
            return null;
        });

        // Creator (no products.edit) gets 403 on edit page
        $this->actingAs($userCreate)
            ->get(route('products.sales-price-snapshot.upload.page'))
            ->assertStatus(403);

        // Editor (has products.edit) gets 200
        $this->actingAs($userEdit)
            ->get(route('products.sales-price-snapshot.upload.page'))
            ->assertStatus(200);
            
        // For imports page (index.blade.php), Editor should see the snapshot link
        $response = $this->actingAs($userEdit)->get(route('products.imports.index'));
        $response->assertStatus(200);
        $response->assertSee(route('products.sales-price-snapshot.upload.page'));
        // But shouldn't see upload product link
        $response->assertDontSee(route('products.upload.page'));
    }

    public function test_actionable_batch_failure_reporting()
    {
        $path = 'imports/products/missing.xlsx';

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
        $this->assertEquals('XLSX file not found.', $batch->error_message);
        
        // Ensure UI renders it
        $this->actingAs($this->user);
        // Assuming super admin or whatever for basic view
        // $this->user needs setting session
        session(['setting_id' => $this->setting->id]);
        $this->withoutMiddleware(['role.setting']);
        \Illuminate\Support\Facades\Gate::before(fn() => true);
        
        $response = $this->get(route('products.imports.show', $batch->id));
        $response->assertSee('Batch Error:');
        $response->assertSee('XLSX file not found.');
    }

    public function test_duplicate_badge_rendering_and_all_tier_rendering()
    {
        \Illuminate\Support\Facades\DB::table('products')->insert([
            'id' => 7, 'product_code' => 'SKU-DUP', 'product_name' => 'DUP ITEM', 'setting_id' => $this->setting->id, 'unit_id' => 1, 'stock_managed' => 1, 'product_quantity' => 0, 'product_cost' => 0, 'product_price' => 0, 'is_sold' => 1, 'is_purchased' => 1, 'purchase_price' => 0, 'sale_price' => 0, 'product_tax_type' => 1
        ]);

        $path = $this->createXlsxFile('test_badges', [
            ['Name*', 'ProductCode', 'SellPrice', 'Stock'],
            ['* Dup Item', 'SKU-DUP', '1000', '10'],
            ['* Dup Item', 'SKU-DUP', '1000', '10'], // Equivalent duplicate
        ]);

        $batch = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $this->location->id,
            'source_csv_path' => $path,
            'status' => 'queued',
            'import_type' => ProductImportBatch::TYPE_SALES_PRICE_SNAPSHOT,
        ]);

        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();
        
        session(['setting_id' => $this->setting->id]);
        
        $this->withoutMiddleware(['role.setting']);
        \Illuminate\Support\Facades\Gate::before(fn() => true);
        $response = $this->actingAs($this->user)->get(route('products.imports.show', $batch->id));
        
        // Assert we see the success badge for the first one
        $response->assertSee('<span class="badge bg-success">imported</span>', false);
        // Assert we see the warning duplicate badge for the second one
        $response->assertSee('<span class="badge bg-warning text-dark">duplicate</span>', false);
        
        // Assert tier UI rendering
        $response->assertSee('T1:');
        $response->assertSee('T2:');
        $response->assertSee('1,000.00'); // Assuming formatting output
    }

    public function test_database_transaction_rollback_on_failure()
    {
        // Add a test row that will pass validation
        $batch = ProductImportBatch::create([
            'user_id' => $this->user->id,
            'location_id' => $this->location->id,
            'import_type' => 'sales_price_snapshot',
            'status' => 'processing',
            'source_csv_path' => 'dummy.xlsx',
        ]);
        
        $productId = 999;
        \Illuminate\Support\Facades\DB::table('products')->insert([
            'id' => $productId, 'product_code' => 'FAIL123', 'product_name' => 'FAIL PRODUCT', 'setting_id' => $this->setting->id,
            'unit_id' => 1, 'stock_managed' => 1, 'product_quantity' => 0, 'product_cost' => 0, 'product_price' => 0, 'is_sold' => 1, 'is_purchased' => 1, 'purchase_price' => 0, 'sale_price' => 0, 'product_tax_type' => 1
        ]);
        
        $productPrice = \Modules\Product\Entities\ProductPrice::firstOrNew(['product_id' => $productId, 'setting_id' => $this->setting->id]);
        $productPrice->sale_price = 1000;
        $productPrice->save();

        ProductImportRow::create([
            'batch_id' => $batch->id,
            'product_id' => $productId,
            'status' => null,
            'row_number' => 2,
            'raw_json' => ['name*' => '* FAIL PRODUCT', 'sellprice' => 5000, 'stock' => '100'],
            'result_metadata' => [
                'clean_product_name' => 'FAIL PRODUCT',
                'sell_price' => 5000.00,
                'imported_stock' => 100,
                'setting_id' => $this->setting->id,
                'target_location_id' => $this->location->id,
                'is_pkp' => true,
            ],
        ]);

        // Throw an exception when saving the row to simulate failure AFTER price is saved
        \Illuminate\Support\Facades\Event::listen('eloquent.saving: Modules\Product\Entities\ProductImportRow', function ($model) {
            // Only throw if status is being changed to 'imported' (i.e. during audit write)
            if ($model->isDirty('status') && $model->status === 'imported') {
                throw new \Exception('Simulated database failure during audit write');
            }
        });

        // Run the job logic
        (new ProcessSalesPriceSnapshotBatch($batch->id))->handle();

        $rows = ProductImportRow::where('batch_id', $batch->id)->get();
        
        // Assert row is marked as error (by the catch block)
        $this->assertEquals('error', $rows[0]->status);
        $this->assertStringContainsString('Simulated database failure during audit write', $rows[0]->error_message);
        
        // Assert price was rolled back / not changed
        $updatedPrice = \Modules\Product\Entities\ProductPrice::where('product_id', $productId)->first();
        $this->assertEquals(1000, $updatedPrice->sale_price);
    }
}
