<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductImportBatch;
use Modules\Product\Jobs\ProcessProductImportBatch;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetails;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SalesHppSnapshotImportTest extends TestCase
{
    use RefreshDatabase;

    private Setting $tigaNusa;
    private Setting $topIt;
    private Setting $perdana;
    private Setting $daizu;

    protected function setUp(): void
    {
        parent::setUp();
        
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);
        \Illuminate\Support\Facades\Gate::before(fn() => true);
        
        $this->tigaNusa = Setting::factory()->create(['company_name' => 'CV TIGA NUSA COMPUTER']);
        $this->topIt = Setting::factory()->create(['company_name' => 'CV TOP IT INTERNUSA']);
        $this->perdana = Setting::factory()->create(['company_name' => 'PERDANA']);
        $this->daizu = Setting::factory()->create(['company_name' => 'DAIZU']);
        
        Location::factory()->create(['setting_id' => $this->tigaNusa->id]);
        Location::factory()->create(['setting_id' => $this->topIt->id]);
        Location::factory()->create(['setting_id' => $this->perdana->id]);
        Location::factory()->create(['setting_id' => $this->daizu->id]);
    }

    public function test_it_detects_sales_hpp_snapshot_and_processes_successfully()
    {
        $unit = \Modules\Setting\Entities\Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);
        $product = Product::create([
            'product_name' => 'LAPTOP ACER',
            'product_code' => 'SKU-TEST-1',
            'product_quantity' => 0,
            'product_price' => 0,
            'product_cost' => 0,
            'setting_id' => $this->tigaNusa->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
        ]);
        
        // Setup Sale for Tiga Nusa
        $sale = Sale::create([
            'setting_id' => $this->tigaNusa->id,
            'imported_sales_reference_number' => 'INV-001',
            'date' => now(),
            'customer_name' => 'Test',
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'status' => 'DISPATCHED',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'reference' => 'TEST-001',
        ]);
        
        $saleDetail = SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => 200,
            'unit_price' => 100,
            'sub_total' => 200,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
            'cost_unit_snapshot' => 100,
            'cost_total_snapshot' => 200,
            'cost_snapshot_source' => 'SALES_COST_SERVICE',
            'product_name' => 'LAPTOP ACER',
            'product_code' => 'SKU-TEST-1',
        ]);

        $csvContent = "Tipe Transaksi,No. Transaksi,Barang,Mutasi,Harga Rata-rata\n" .
                      "Sales Invoice,INV-001,* Laptop Acer,-2,150.50\n" .
                      "Sales Invoice,INV-001,* Laptop Acer,2,150.50\n" . // Should be ignored (positive mutasi)
                      "Purchase,INV-002,* Laptop Acer,-1,100\n"; // Should be ignored (not sales)
                      
        $file = UploadedFile::fake()->createWithContent('hpp.csv', $csvContent);
        
        // Simulate uploading through the dedicated endpoint
        $response = $this->post(route('products.sales-hpp-snapshot.upload'), ['file' => $file]);
        $response->assertSessionHasNoErrors();

        $batch = ProductImportBatch::first();
        $response->assertRedirect(route('products.imports.show', $batch));
        
        $this->assertEquals(ProductImportBatch::TYPE_SALES_HPP_SNAPSHOT, $batch->import_type);
        
        (new ProcessProductImportBatch($batch->id))->handle();
        
        $batch->refresh();
        $this->assertEquals('completed', $batch->status);
        $this->assertEquals(1, $batch->success_rows);
        
        // Assert updated sale detail
        $saleDetail->refresh();
        $this->assertEquals(150.50, $saleDetail->cost_unit_snapshot);
        $this->assertEquals(301.00, $saleDetail->cost_total_snapshot);
        $this->assertEquals('HPP_SNAPSHOT_IMPORT', $saleDetail->cost_snapshot_source);
    }

    public function test_it_handles_quantity_mismatch_and_no_match()
    {
        $unit = \Modules\Setting\Entities\Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);
        $product = Product::create([
            'product_name' => 'LAPTOP ACER',
            'product_code' => 'SKU-TEST-2',
            'product_quantity' => 0,
            'product_price' => 0,
            'product_cost' => 0,
            'setting_id' => $this->tigaNusa->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
        ]);
        
        $sale = Sale::create([
            'setting_id' => $this->tigaNusa->id,
            'imported_sales_reference_number' => 'INV-001',
            'date' => now(),
            'customer_name' => 'Test',
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'status' => 'DISPATCHED',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'reference' => 'TEST-002',
        ]);
        
        SaleDetails::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_name' => 'LAPTOP ACER',
            'product_code' => 'SKU-TEST-2',
            'quantity' => 2,
            'price' => 200,
            'unit_price' => 100,
            'sub_total' => 200,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $csvContent = "Tipe Transaksi,No. Transaksi,Barang,Mutasi,Harga Rata-rata\n" .
                      "Sales Invoice,INV-001,* Laptop Acer,-5,150.50\n" . // Mismatch quantity
                      "Sales Invoice,INV-002,* Laptop Acer,-2,150.50\n"; // No match
                      
        $file = UploadedFile::fake()->createWithContent('hpp.csv', $csvContent);
        $response = $this->post(route('products.sales-hpp-snapshot.upload'), ['file' => $file]);
        $response->assertSessionHasNoErrors();

        $batch = ProductImportBatch::latest()->first();
        (new ProcessProductImportBatch($batch->id))->handle();
        
        $batch->refresh();
        $this->assertEquals(0, $batch->success_rows);
        $this->assertEquals(2, $batch->error_rows);
    }

    public function test_it_handles_repeated_sale_details()
    {
        $unit = \Modules\Setting\Entities\Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);
        $product = Product::create([
            'product_name' => 'LAPTOP ACER',
            'product_code' => 'SKU-TEST-3',
            'product_quantity' => 0,
            'product_price' => 0,
            'product_cost' => 0,
            'setting_id' => $this->tigaNusa->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
        ]);
        
        $sale = Sale::create([
            'setting_id' => $this->tigaNusa->id,
            'imported_sales_reference_number' => 'INV-001',
            'date' => now(),
            'customer_name' => 'Test',
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'status' => 'DISPATCHED',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'reference' => 'TEST-003',
        ]);
        
        $detail1 = SaleDetails::create(['sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'LAPTOP ACER', 'product_code' => 'SKU-TEST-3', 'quantity' => 1, 'price' => 200, 'unit_price' => 200, 'sub_total' => 200, 'product_discount_amount' => 0, 'product_tax_amount' => 0]);
        $detail2 = SaleDetails::create(['sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'LAPTOP ACER', 'product_code' => 'SKU-TEST-3', 'quantity' => 1, 'price' => 200, 'unit_price' => 200, 'sub_total' => 200, 'product_discount_amount' => 0, 'product_tax_amount' => 0]);

        $csvContent = "Tipe Transaksi,No. Transaksi,Barang,Mutasi,Harga Rata-rata\n" .
                      "Sales Invoice,INV-001,* Laptop Acer,-1,100\n" .
                      "Sales Invoice,INV-001,* Laptop Acer,-1,200\n";
                      
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('hpp.csv', $csvContent);
        $this->post(route('products.sales-hpp-snapshot.upload'), ['file' => $file]);

        $batch = ProductImportBatch::latest()->first();
        (new ProcessProductImportBatch($batch->id))->handle();
        
        $batch->refresh();
        $this->assertEquals(2, $batch->success_rows);
        $this->assertEquals(0, $batch->error_rows);
        
        $detail1->refresh();
        $this->assertEquals(100, $detail1->cost_unit_snapshot);
        $detail2->refresh();
        $this->assertEquals(200, $detail2->cost_unit_snapshot);
    }

    public function test_it_retains_match_index_on_retry()
    {
        $unit = \Modules\Setting\Entities\Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);
        $product = Product::create([
            'product_name' => 'LAPTOP ACER',
            'product_code' => 'SKU-TEST-4',
            'product_quantity' => 0,
            'product_price' => 0,
            'product_cost' => 0,
            'setting_id' => $this->tigaNusa->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
        ]);
        
        $sale = Sale::create([
            'setting_id' => $this->tigaNusa->id,
            'imported_sales_reference_number' => 'INV-001',
            'date' => now(),
            'customer_name' => 'Test',
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'status' => 'DISPATCHED',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'reference' => 'TEST-004',
        ]);
        
        $detail1 = SaleDetails::create(['sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'LAPTOP ACER', 'product_code' => 'SKU-TEST-4', 'quantity' => 1, 'price' => 200, 'unit_price' => 200, 'sub_total' => 200, 'product_discount_amount' => 0, 'product_tax_amount' => 0]);
        $detail2 = SaleDetails::create(['sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'LAPTOP ACER', 'product_code' => 'SKU-TEST-4', 'quantity' => 1, 'price' => 200, 'unit_price' => 200, 'sub_total' => 200, 'product_discount_amount' => 0, 'product_tax_amount' => 0]);

        $csvContent = "Tipe Transaksi,No. Transaksi,Barang,Mutasi,Harga Rata-rata\n" .
                      "Sales Invoice,INV-001,* Laptop Acer,-1,100\n" .
                      "Sales Invoice,INV-001,* Laptop Acer,-1,invalid\n"; // 2nd row will fail
                      
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('hpp.csv', $csvContent);
        $this->post(route('products.sales-hpp-snapshot.upload'), ['file' => $file]);

        $batch = ProductImportBatch::latest()->first();
        (new ProcessProductImportBatch($batch->id))->handle();
        
        $detail1->refresh();
        $this->assertEquals(100, $detail1->cost_unit_snapshot);
        $detail2->refresh();
        $this->assertNull($detail2->cost_unit_snapshot);

        // Now "fix" the row and retry the batch
        $row2 = \Modules\Product\Entities\ProductImportRow::where('batch_id', $batch->id)->orderBy('id', 'desc')->first();
        $p = (array)$row2->raw_json;
        $p['source_hpp'] = 300;
        $row2->raw_json = $p;
        $row2->status = null; // Mark as pending
        $row2->save();
        
        (new ProcessProductImportBatch($batch->id))->handle();
        $detail2->refresh();
        $this->assertEquals(300, $detail2->cost_unit_snapshot);
    }

    public function test_it_handles_normalized_name_differences()
    {
        $unit = \Modules\Setting\Entities\Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);
        $product = Product::create([
            'product_name' => 'ALFA-INK CANON BLACK 100CC', // Name with hyphen
            'product_code' => 'SKU-TEST-5',
            'product_quantity' => 0,
            'product_price' => 0,
            'product_cost' => 0,
            'setting_id' => $this->tigaNusa->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
        ]);
        
        $sale = Sale::create([
            'setting_id' => $this->tigaNusa->id,
            'imported_sales_reference_number' => 'INV-001',
            'date' => now(),
            'customer_name' => 'Test',
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'status' => 'DISPATCHED',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'reference' => 'TEST-005',
        ]);
        
        $detail = SaleDetails::create(['sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'ALFA-INK CANON BLACK 100CC', 'product_code' => 'SKU-TEST-5', 'quantity' => 1, 'price' => 200, 'unit_price' => 200, 'sub_total' => 200, 'product_discount_amount' => 0, 'product_tax_amount' => 0]);

        // CSV contains name without hyphen but should still normalize and match
        $csvContent = "Tipe Transaksi,No. Transaksi,Barang,Mutasi,Harga Rata-rata\n" .
                      "Sales Invoice,INV-001,* ALFA INK CANON BLACK 100CC,-1,100\n";
                      
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('hpp.csv', $csvContent);
        $this->post(route('products.sales-hpp-snapshot.upload'), ['file' => $file]);

        $batch = ProductImportBatch::latest()->first();
        (new ProcessProductImportBatch($batch->id))->handle();
        
        $detail->refresh();
        $this->assertEquals(100, $detail->cost_unit_snapshot);
    }

    public function test_it_uses_similarity_fallback_for_alias_differences()
    {
        $unit = \Modules\Setting\Entities\Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);
        $product = Product::create([
            'product_name' => 'BATERAI EVEREADY 9V',
            'product_code' => 'SKU-TEST-6',
            'product_quantity' => 0,
            'product_price' => 0,
            'product_cost' => 0,
            'setting_id' => $this->tigaNusa->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
        ]);
        
        $sale = Sale::create([
            'setting_id' => $this->tigaNusa->id,
            'imported_sales_reference_number' => 'INV-002',
            'date' => now(),
            'customer_name' => 'Test',
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'status' => 'DISPATCHED',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'reference' => 'TEST-006',
        ]);
        
        $detail = SaleDetails::create(['sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'BATERAI EVEREADY 9V', 'product_code' => 'SKU-TEST-6', 'quantity' => 1, 'price' => 200, 'unit_price' => 200, 'sub_total' => 200, 'product_discount_amount' => 0, 'product_tax_amount' => 0]);

        // CSV contains alias difference that won't strictly normalize to exact match
        $csvContent = "Tipe Transaksi,No. Transaksi,Barang,Mutasi,Harga Rata-rata\n" .
                      "Sales Invoice,INV-002,* BATERAI EVEREADY / CAMELION 9V,-1,100\n";
                      
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('hpp2.csv', $csvContent);
        $this->post(route('products.sales-hpp-snapshot.upload'), ['file' => $file]);

        $batch = ProductImportBatch::latest()->first();
        (new ProcessProductImportBatch($batch->id))->handle();
        
        $detail->refresh();
        // Should be successfully matched via fallback
        $this->assertEquals(100, $detail->cost_unit_snapshot);
    }

    public function test_it_uses_explicit_alias_canonicalization_for_weak_matches()
    {
        $unit = \Modules\Setting\Entities\Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);
        $product = Product::create([
            'product_name' => 'MOUSE VOTRE / SANURPRO',
            'product_code' => 'SKU-TEST-7',
            'product_quantity' => 0,
            'product_price' => 0,
            'product_cost' => 0,
            'setting_id' => $this->tigaNusa->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
        ]);
        
        $sale = Sale::create([
            'setting_id' => $this->tigaNusa->id,
            'imported_sales_reference_number' => 'INV-003',
            'date' => now(),
            'customer_name' => 'Test',
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'status' => 'DISPATCHED',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'reference' => 'TEST-007',
        ]);
        
        $detail = SaleDetails::create(['sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'MOUSE VOTRE / SANURPRO', 'product_code' => 'SKU-TEST-7', 'quantity' => 1, 'price' => 200, 'unit_price' => 200, 'sub_total' => 200, 'product_discount_amount' => 0, 'product_tax_amount' => 0]);

        $csvContent = "Tipe Transaksi,No. Transaksi,Barang,Mutasi,Harga Rata-rata\n" .
                      "Sales Invoice,INV-003,* MOUSE VOTRE / VOXY,-1,100\n";
                      
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('hpp3.csv', $csvContent);
        $this->post(route('products.sales-hpp-snapshot.upload'), ['file' => $file]);

        $batch = ProductImportBatch::latest()->first();
        (new ProcessProductImportBatch($batch->id))->handle();
        
        $detail->refresh();
        // Should be successfully matched via exact match after alias canonicalization
        $this->assertEquals(100, $detail->cost_unit_snapshot);
    }

    public function test_it_preserves_subtypes_for_odner_via_explicit_aliases()
    {
        $unit = \Modules\Setting\Entities\Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);
        
        $product1 = Product::create([
            'product_name' => 'ODNER FOLIO 75MM',
            'product_code' => 'SKU-ODN-F',
            'product_quantity' => 0, 'product_price' => 0, 'product_cost' => 0,
            'setting_id' => $this->perdana->id, 'unit_id' => $unit->id, 'base_unit_id' => $unit->id,
        ]);
        
        $product2 = Product::create([
            'product_name' => 'ODNER KWT 75MM',
            'product_code' => 'SKU-ODN-K',
            'product_quantity' => 0, 'product_price' => 0, 'product_cost' => 0,
            'setting_id' => $this->perdana->id, 'unit_id' => $unit->id, 'base_unit_id' => $unit->id,
        ]);
        
        $sale = Sale::create([
            'setting_id' => $this->perdana->id,
            'imported_sales_reference_number' => 'JL4940',
            'date' => now(), 'customer_name' => 'Test', 'total_amount' => 0, 'paid_amount' => 0, 'due_amount' => 0,
            'status' => 'DISPATCHED', 'payment_status' => 'Paid', 'payment_method' => 'Cash',
            'reference' => 'TEST-008',
        ]);
        
        // Same quantity for both to ensure fallback doesn't mix them up
        $detail1 = SaleDetails::create(['sale_id' => $sale->id, 'product_id' => $product1->id, 'product_name' => 'ODNER FOLIO 75MM', 'product_code' => 'SKU-ODN-F', 'quantity' => 20, 'price' => 200, 'unit_price' => 200, 'sub_total' => 4000, 'product_discount_amount' => 0, 'product_tax_amount' => 0]);
        $detail2 = SaleDetails::create(['sale_id' => $sale->id, 'product_id' => $product2->id, 'product_name' => 'ODNER KWT 75MM', 'product_code' => 'SKU-ODN-K', 'quantity' => 20, 'price' => 200, 'unit_price' => 200, 'sub_total' => 4000, 'product_discount_amount' => 0, 'product_tax_amount' => 0]);

        $csvContent = "Tipe Transaksi,No. Transaksi,Barang,Mutasi,Harga Rata-rata\n" .
                      "Sales Invoice,JL4940,ODNER FOLIO 75MM BENEX LAMI,-20,100\n" .
                      "Sales Invoice,JL4940,ODNER KWT 75MM BENEX LAMI KECIL KWARTO,-20,150\n";
                      
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('hpp_odner.csv', $csvContent);
        $this->post(route('products.sales-hpp-snapshot.upload'), ['file' => $file]);

        $batch = ProductImportBatch::latest()->first();
        (new ProcessProductImportBatch($batch->id))->handle();
        
        $detail1->refresh();
        $detail2->refresh();
        
        $this->assertEquals(100, $detail1->cost_unit_snapshot);
        $this->assertEquals(150, $detail2->cost_unit_snapshot);
    }
    
    public function test_it_uses_explicit_alias_canonicalization_for_sambungan_kabel_lan()
    {
        $unit = \Modules\Setting\Entities\Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);
        $product = Product::create([
            'product_name' => 'SAMBUNGAN KABEL LAN',
            'product_code' => 'SKU-LAN-1',
            'product_quantity' => 0, 'product_price' => 0, 'product_cost' => 0,
            'setting_id' => $this->perdana->id, 'unit_id' => $unit->id, 'base_unit_id' => $unit->id,
        ]);
        
        $sale = Sale::create([
            'setting_id' => $this->perdana->id,
            'imported_sales_reference_number' => 'INV-004',
            'date' => now(), 'customer_name' => 'Test', 'total_amount' => 0, 'paid_amount' => 0, 'due_amount' => 0,
            'status' => 'DISPATCHED', 'payment_status' => 'Paid', 'payment_method' => 'Cash',
            'reference' => 'TEST-009',
        ]);
        
        $detail = SaleDetails::create(['sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'SAMBUNGAN KABEL LAN', 'product_code' => 'SKU-LAN-1', 'quantity' => 5, 'price' => 200, 'unit_price' => 200, 'sub_total' => 1000, 'product_discount_amount' => 0, 'product_tax_amount' => 0]);

        $csvContent = "Tipe Transaksi,No. Transaksi,Barang,Mutasi,Harga Rata-rata\n" .
                      "Sales Invoice,INV-004,SAMBUNGAN KABEL LAN RJ45 INDOOR,-5,50\n";
                      
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('hpp_lan.csv', $csvContent);
        $this->post(route('products.sales-hpp-snapshot.upload'), ['file' => $file]);

        $batch = ProductImportBatch::latest()->first();
        (new ProcessProductImportBatch($batch->id))->handle();
        
        $detail->refresh();
        $this->assertEquals(50, $detail->cost_unit_snapshot);
    }

    public function test_it_appends_sales_without_hpp_snapshot_report_rows_for_import_period()
    {
        $unit = \Modules\Setting\Entities\Unit::create(['name' => 'Pcs', 'short_name' => 'pcs']);
        $coveredProduct = Product::create([
            'product_name' => 'LAPTOP ACER',
            'product_code' => 'SKU-COVERED',
            'product_quantity' => 0,
            'product_price' => 0,
            'product_cost' => 0,
            'setting_id' => $this->tigaNusa->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
        ]);
        $uncoveredProduct = Product::create([
            'product_name' => 'SERVICE',
            'product_code' => 'SKU-SERVICE',
            'product_quantity' => 0,
            'product_price' => 0,
            'product_cost' => 0,
            'setting_id' => $this->tigaNusa->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
        ]);
        $outsideProduct = Product::create([
            'product_name' => 'OUTSIDE PERIOD',
            'product_code' => 'SKU-OUTSIDE',
            'product_quantity' => 0,
            'product_price' => 0,
            'product_cost' => 0,
            'setting_id' => $this->tigaNusa->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
        ]);

        $coveredSale = Sale::create([
            'setting_id' => $this->tigaNusa->id,
            'imported_sales_reference_number' => 'INV-COVERED',
            'date' => '2020-10-05',
            'customer_name' => 'Test',
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'status' => 'DISPATCHED',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'reference' => 'TEST-COVERED',
        ]);
        $coveredDetail = SaleDetails::create([
            'sale_id' => $coveredSale->id,
            'product_id' => $coveredProduct->id,
            'product_name' => 'LAPTOP ACER',
            'product_code' => 'SKU-COVERED',
            'quantity' => 2,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 200,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $uncoveredSale = Sale::create([
            'setting_id' => $this->tigaNusa->id,
            'imported_sales_reference_number' => 'INV-UNCOVERED',
            'date' => '2020-10-06',
            'customer_name' => 'Test',
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'status' => 'DISPATCHED',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'reference' => 'TEST-UNCOVERED',
        ]);
        $uncoveredDetail = SaleDetails::create([
            'sale_id' => $uncoveredSale->id,
            'product_id' => $uncoveredProduct->id,
            'product_name' => 'SERVICE',
            'product_code' => 'SKU-SERVICE',
            'quantity' => 1,
            'price' => 50000,
            'unit_price' => 50000,
            'sub_total' => 50000,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $outsideSale = Sale::create([
            'setting_id' => $this->tigaNusa->id,
            'imported_sales_reference_number' => 'INV-OUTSIDE',
            'date' => '2020-11-01',
            'customer_name' => 'Test',
            'total_amount' => 0,
            'paid_amount' => 0,
            'due_amount' => 0,
            'status' => 'DISPATCHED',
            'payment_status' => 'Paid',
            'payment_method' => 'Cash',
            'reference' => 'TEST-OUTSIDE',
        ]);
        SaleDetails::create([
            'sale_id' => $outsideSale->id,
            'product_id' => $outsideProduct->id,
            'product_name' => 'OUTSIDE PERIOD',
            'product_code' => 'SKU-OUTSIDE',
            'quantity' => 1,
            'price' => 100,
            'unit_price' => 100,
            'sub_total' => 100,
            'product_discount_amount' => 0,
            'product_tax_amount' => 0,
        ]);

        $csvContent = "Tanggal,Tipe Transaksi,No. Transaksi,Barang,Mutasi,Harga Rata-rata\n" .
            "05/10/2020,Sales Invoice,INV-COVERED,* Laptop Acer,-2,150\n" .
            "06/10/2020,Sales Invoice,INV-COVERED,* Laptop Acer,2,150\n";

        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('hpp_coverage.csv', $csvContent);
        $this->post(route('products.sales-hpp-snapshot.upload'), ['file' => $file]);

        $batch = ProductImportBatch::latest()->first();
        (new ProcessProductImportBatch($batch->id))->handle();

        $coveredDetail->refresh();
        $this->assertEquals('HPP_SNAPSHOT_IMPORT', $coveredDetail->cost_snapshot_source);

        $reportRows = \Modules\Product\Entities\ProductImportRow::where('batch_id', $batch->id)
            ->where('status', 'skipped')
            ->where('error_message', 'like', 'Sales detail tidak memiliki HPP snapshot%')
            ->get();

        $this->assertCount(1, $reportRows);
        $meta = $reportRows->first()->result_metadata;
        $this->assertEquals($uncoveredDetail->id, $meta['matched_sale_detail_id']);
        $this->assertEquals('SERVICE', $meta['matched_product_name']);
        $this->assertEquals('INV-UNCOVERED', $meta['imported_sales_reference_number']);
    }
}
