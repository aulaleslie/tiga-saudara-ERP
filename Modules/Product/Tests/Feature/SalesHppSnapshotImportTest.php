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
}
