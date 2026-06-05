<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductImportBatch;
use Modules\Product\Entities\ProductImportRow;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
use Modules\Product\Jobs\ProcessProductImportBatch;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class StockSnapshotCompletionTest extends TestCase
{
    use RefreshDatabase;

    private Setting $tigaNusa;
    private Setting $topIt;
    private Setting $perdana;
    private Location $tigaNusaLocation;
    private Location $topItLocation;
    private Location $perdanaLocation;

    protected function setUp(): void
    {
        parent::setUp();

        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);
        \Illuminate\Support\Facades\Gate::before(fn() => true);

        $this->tigaNusa = Setting::factory()->create([
            'company_name' => 'CV TIGA NUSA COMPUTER',
            'is_pkp' => true,
        ]);
        $this->topIt = Setting::factory()->create([
            'company_name' => 'CV TOP IT INTERNUSA',
            'is_pkp' => false,
        ]);
        $this->perdana = Setting::factory()->create([
            'company_name' => 'PERDANA',
            'is_pkp' => false,
        ]);

        $this->tigaNusaLocation = Location::factory()->create(['setting_id' => $this->tigaNusa->id]);
        $this->topItLocation = Location::factory()->create(['setting_id' => $this->topIt->id]);
        $this->perdanaLocation = Location::factory()->create(['setting_id' => $this->perdana->id]);
    }

    // ======================================================================
    // 9.7 UI/request tests
    // ======================================================================

    public function test_stock_snapshot_upload_page_is_accessible()
    {
        $response = $this->get(route('products.stock-snapshot.upload.page'));
        $response->assertOk();
        $response->assertSee('Upload Stok Snapshot');
        $response->assertSee('Tipe Import: Stok Snapshot');
    }

    public function test_stock_snapshot_template_has_correct_headers()
    {
        $response = $this->get(route('products.stock-snapshot.template'));
        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('Product Code', $content);
        $this->assertStringContainsString('Product Name', $content);
        $this->assertStringContainsString('Unassigned', $content);
        $this->assertStringContainsString('Total Quantity', $content);
        $this->assertStringContainsString('Product Unit', $content);
    }

    public function test_import_type_visible_in_batch_list()
    {
        $this->uploadStockSnapshotCsv(
            "Product Code,Product Name,Unassigned,Total Quantity,Product Unit\nSKU-001,Keyboard,0,5,Pcs"
        );
        $batch = ProductImportBatch::first();
        (new ProcessProductImportBatch($batch->id))->handle();

        $response = $this->get(route('products.imports.index'));
        $response->assertOk();
        $response->assertSee('Stok Snapshot');
    }

    public function test_stock_snapshot_upload_page_shows_marker_guidance()
    {
        $response = $this->get(route('products.stock-snapshot.upload.page'));
        $response->assertOk();
        $response->assertSee('CV TIGA NUSA COMPUTER');
        $response->assertSee('CV TOP IT INTERNUSA');
        $response->assertSee('PERDANA');
        $response->assertSee('di awal nama');
        $response->assertSee('di akhir nama');
    }

    public function test_batch_detail_shows_stock_effect_columns_for_snapshot()
    {
        $this->uploadStockSnapshotCsv(
            "Product Code,Product Name,Unassigned,Total Quantity,Product Unit\nSKU-001,* Laptop Acer,0,10,Pcs"
        );
        $batch = ProductImportBatch::first();
        (new ProcessProductImportBatch($batch->id))->handle();

        $response = $this->get(route('products.imports.show', $batch));
        $response->assertOk();
        $response->assertSee('Pemilik');
        $response->assertSee('Lokasi');
        $response->assertSee('Total Qty');
    }

    // ======================================================================
    // 10.1 Persist row references (created_stock_id, created_txn_id)
    // ======================================================================

    public function test_successful_row_persists_stock_and_txn_references()
    {
        $this->uploadAndProcessSnapshot(
            "Product Code,Product Name,Unassigned,Total Quantity,Product Unit\nSKU-R01,* Test Product Ref,0,25,Pcs"
        );

        $row = ProductImportRow::first();
        $this->assertEquals('imported', $row->status);
        $this->assertNotNull($row->created_stock_id);
        $this->assertNotNull($row->created_txn_id);

        // Verify references point to real records
        $this->assertNotNull(ProductStock::find($row->created_stock_id));
        $this->assertNotNull(Transaction::find($row->created_txn_id));
    }

    // ======================================================================
    // 10.2 Persist row-level result metadata
    // ======================================================================

    public function test_successful_row_has_result_metadata()
    {
        $this->uploadAndProcessSnapshot(
            "Product Code,Product Name,Unassigned,Total Quantity,Product Unit\nSKU-M01,* MetaTest Product,0,15,Pcs"
        );

        $row = ProductImportRow::first();
        $meta = $row->result_metadata;

        $this->assertIsArray($meta);
        $this->assertEquals('*', $meta['raw_marker']);
        $this->assertNotEmpty($meta['clean_product_name']);
        $this->assertEquals($this->tigaNusa->id, $meta['owner_setting_id']);
        $this->assertNotEmpty($meta['owner_setting_name']);
        $this->assertEquals($this->tigaNusaLocation->id, $meta['target_location_id']);
        $this->assertEquals(15, $meta['total_quantity']);
        $this->assertEquals(0, $meta['previous_quantity']);
        $this->assertEquals(15, $meta['after_quantity']);
    }

    // ======================================================================
    // 10.3 Real warehouse_stock_quantity.csv behavior coverage
    // ======================================================================

    public function test_blank_quoted_product_code_is_treated_as_empty()
    {
        // Real CSV has "" as blank product codes
        $csv = "Product Code,Product Name,Unassigned,Total Quantity,Product Unit\n" .
               "\"\",* Keyboard Mechanical,0,5,PCS\n";

        $this->uploadAndProcessSnapshot($csv);

        $row = ProductImportRow::first();
        $this->assertEquals('imported', $row->status);

        $product = Product::find($row->product_id);
        $this->assertNotNull($product);
        // Product code should be null (blank), not literally ""
        $this->assertTrue(
            $product->product_code === null || $product->product_code === '',
            'Blank quoted product code should result in null/empty product_code'
        );
    }

    public function test_quoted_product_names_with_inches_marks()
    {
        // Real CSV has product names with double-quote inch marks inside quotes
        $csv = "Product Code,Product Name,Unassigned,Total Quantity,Product Unit\n" .
               "\"\",\"* ACER 3 A314 - 35 N5100 4GB 256GB SSD 14\"\"\",0,5,UNIT\n";

        $this->uploadAndProcessSnapshot($csv);

        $row = ProductImportRow::first();
        $this->assertEquals('imported', $row->status);

        $product = Product::find($row->product_id);
        $this->assertNotNull($product);
        // The inch mark should be preserved in the clean name (marker removed)
        $this->assertStringContainsString('14"', $product->product_name);
    }

    // ======================================================================
    // 10.4 Missing owner setting error — no mutations
    // ======================================================================

    public function test_missing_owner_setting_creates_no_mutations()
    {
        // Delete the PERDANA setting so the default marker can't resolve
        $this->perdana->delete();

        $productCountBefore = Product::count();
        $stockCountBefore = ProductStock::count();
        $txnCountBefore = Transaction::count();

        $csv = "Product Code,Product Name,Unassigned,Total Quantity,Product Unit\n" .
               "SKU-ERR1,Unknown Owner Product,0,10,Pcs\n";

        $this->uploadAndProcessSnapshot($csv);

        $row = ProductImportRow::first();
        $this->assertEquals('error', $row->status);
        $this->assertStringContainsString('Pemilik atau lokasi tidak ditemukan', $row->error_message);

        // No mutations should have occurred
        $this->assertEquals($productCountBefore, Product::count());
        $this->assertEquals($stockCountBefore, ProductStock::count());
        $this->assertEquals($txnCountBefore, Transaction::count());
    }

    // ======================================================================
    // 10.5 Missing owner location error — no mutations
    // ======================================================================

    public function test_missing_owner_location_creates_no_mutations()
    {
        // Delete all locations for PERDANA
        Location::where('setting_id', $this->perdana->id)->delete();

        $productCountBefore = Product::count();
        $stockCountBefore = ProductStock::count();
        $txnCountBefore = Transaction::count();

        $csv = "Product Code,Product Name,Unassigned,Total Quantity,Product Unit\n" .
               "SKU-ERR2,No Location Product,0,10,Pcs\n";

        $this->uploadAndProcessSnapshot($csv);

        $row = ProductImportRow::first();
        $this->assertEquals('error', $row->status);

        $this->assertEquals($productCountBefore, Product::count());
        $this->assertEquals($stockCountBefore, ProductStock::count());
        $this->assertEquals($txnCountBefore, Transaction::count());
    }

    // ======================================================================
    // 10.6 PKP bucket routing
    // ======================================================================

    public function test_pkp_owner_writes_quantity_tax_bucket()
    {
        // TigaNusa is PKP (is_pkp = true)
        $csv = "Product Code,Product Name,Unassigned,Total Quantity,Product Unit\n" .
               "SKU-PKP1,* PKP Product Test,0,50,Pcs\n";

        $this->uploadAndProcessSnapshot($csv);

        $row = ProductImportRow::first();
        $this->assertEquals('imported', $row->status);

        $stock = ProductStock::find($row->created_stock_id);
        $this->assertNotNull($stock);
        $this->assertEquals(50, $stock->quantity);
        $this->assertEquals(50, $stock->quantity_tax);
        $this->assertEquals(0, $stock->quantity_non_tax);

        // Check transaction bucket deltas
        $txn = Transaction::find($row->created_txn_id);
        $this->assertNotNull($txn);
        $this->assertEquals(50, $txn->quantity_tax);
        $this->assertEquals(0, $txn->quantity_non_tax);
    }

    // ======================================================================
    // 10.7 Non-PKP bucket routing
    // ======================================================================

    public function test_non_pkp_owner_writes_quantity_non_tax_bucket()
    {
        // TopIt is non-PKP (is_pkp = false)
        $csv = "Product Code,Product Name,Unassigned,Total Quantity,Product Unit\n" .
               "SKU-NPKP1,Non PKP Product TP,0,30,Pcs\n";

        $this->uploadAndProcessSnapshot($csv);

        $row = ProductImportRow::first();
        $this->assertEquals('imported', $row->status);

        $stock = ProductStock::find($row->created_stock_id);
        $this->assertNotNull($stock);
        $this->assertEquals(30, $stock->quantity);
        $this->assertEquals(0, $stock->quantity_tax);
        $this->assertEquals(30, $stock->quantity_non_tax);

        // Check transaction
        $txn = Transaction::find($row->created_txn_id);
        $this->assertNotNull($txn);
        $this->assertEquals(0, $txn->quantity_tax);
        $this->assertEquals(30, $txn->quantity_non_tax);
    }

    // ======================================================================
    // 10.8 Zero quantity tests
    // ======================================================================

    public function test_zero_quantity_creates_stock_row_and_transaction()
    {
        $csv = "Product Code,Product Name,Unassigned,Total Quantity,Product Unit\n" .
               "SKU-Z1,Zero Qty Product,0,0,Pcs\n";

        $this->uploadAndProcessSnapshot($csv);

        $row = ProductImportRow::first();
        $this->assertEquals('imported', $row->status);

        $stock = ProductStock::find($row->created_stock_id);
        $this->assertNotNull($stock);
        $this->assertEquals(0, $stock->quantity);
        $this->assertEquals(0, $stock->quantity_tax);
        $this->assertEquals(0, $stock->quantity_non_tax);

        // Transaction should still be created for audit
        $txn = Transaction::find($row->created_txn_id);
        $this->assertNotNull($txn);
        $this->assertEquals(0, $txn->after_quantity);

        // Product aggregate should be consistent
        $product = Product::find($row->product_id);
        $this->assertEquals(0, $product->product_quantity);
    }

    // ======================================================================
    // 10.9 Negative quantity tests
    // ======================================================================

    public function test_negative_quantity_preserves_negative_snapshot()
    {
        $csv = "Product Code,Product Name,Unassigned,Total Quantity,Product Unit\n" .
               "SKU-NEG1,* Negative Stock Product,0,-10,Pcs\n";

        $this->uploadAndProcessSnapshot($csv);

        $row = ProductImportRow::first();
        $this->assertEquals('imported', $row->status);

        $stock = ProductStock::find($row->created_stock_id);
        $this->assertNotNull($stock);
        $this->assertEquals(-10, $stock->quantity);

        // PKP: tax bucket should hold the negative value
        $this->assertEquals(-10, $stock->quantity_tax);
        $this->assertEquals(0, $stock->quantity_non_tax);

        // Transaction audit
        $txn = Transaction::find($row->created_txn_id);
        $this->assertNotNull($txn);
        $this->assertEquals(-10, $txn->after_quantity);
        $this->assertEquals(-10, $txn->quantity);

        // Product aggregate
        $product = Product::find($row->product_id);
        $this->assertEquals(-10, $product->product_quantity);
    }

    // ======================================================================
    // 10.10 Stock transaction audit tests
    // ======================================================================

    public function test_transaction_audit_captures_full_context()
    {
        $csv = "Product Code,Product Name,Unassigned,Total Quantity,Product Unit\n" .
               "SKU-AUD1,* Audit Test Product,0,42,Pcs\n";

        $this->uploadAndProcessSnapshot($csv);

        $row = ProductImportRow::first();
        $txn = Transaction::find($row->created_txn_id);

        $this->assertNotNull($txn);
        $this->assertEquals($row->product_id, $txn->product_id);
        $this->assertEquals($this->tigaNusa->id, $txn->setting_id);
        $this->assertEquals($this->tigaNusaLocation->id, $txn->location_id);
        $this->assertEquals(0, $txn->previous_quantity);
        $this->assertEquals(42, $txn->after_quantity);
        $this->assertEquals(42, $txn->quantity); // delta from 0 to 42
        $this->assertEquals('ADJ', $txn->type);
        $this->assertEquals('STOCK SNAPSHOT IMPORT OVERWRITE', $txn->reason);
        $this->assertNotNull($txn->user_id);
    }

    // ======================================================================
    // 10.11 Product quantity consistency across multiple owner-location rows
    // ======================================================================

    public function test_product_quantity_consistency_across_multiple_owners()
    {
        // Same clean product name under different markers
        $csv = "Product Code,Product Name,Unassigned,Total Quantity,Product Unit\n" .
               ",* Multi Owner Prod,0,20,Pcs\n" .
               ",Multi Owner Prod TP,0,15,Pcs\n" .
               ",Multi Owner Prod,0,10,Pcs\n";

        $this->uploadAndProcessSnapshot($csv);

        $batch = ProductImportBatch::first();
        $this->assertEquals(3, $batch->success_rows);

        // Should be ONE product (same clean name — BaseModel uppercases stored names)
        $products = Product::whereRaw('LOWER(product_name) = ?', ['multi owner prod'])->get();
        $this->assertCount(1, $products);
        $product = $products->first();

        // Product aggregate = sum of all location stocks = 20 + 15 + 10 = 45
        $this->assertEquals(45, $product->product_quantity);

        // Three separate stock rows at different locations
        $stocks = ProductStock::where('product_id', $product->id)->get();
        $this->assertCount(3, $stocks);

        // Verify each location has the correct quantity
        $tigaNusaStock = $stocks->where('location_id', $this->tigaNusaLocation->id)->first();
        $this->assertNotNull($tigaNusaStock);
        $this->assertEquals(20, $tigaNusaStock->quantity);

        $topItStock = $stocks->where('location_id', $this->topItLocation->id)->first();
        $this->assertNotNull($topItStock);
        $this->assertEquals(15, $topItStock->quantity);

        $perdanaStock = $stocks->where('location_id', $this->perdanaLocation->id)->first();
        $this->assertNotNull($perdanaStock);
        $this->assertEquals(10, $perdanaStock->quantity);
    }

    // ======================================================================
    // Helpers
    // ======================================================================

    private function uploadStockSnapshotCsv(string $csvContent): void
    {
        $file = UploadedFile::fake()->createWithContent('stock.csv', $csvContent);
        $this->post(route('products.stock-snapshot.upload'), ['file' => $file]);
    }

    private function uploadAndProcessSnapshot(string $csvContent): void
    {
        $this->uploadStockSnapshotCsv($csvContent);
        $batch = ProductImportBatch::latest('id')->first();
        (new ProcessProductImportBatch($batch->id))->handle();
    }
}
