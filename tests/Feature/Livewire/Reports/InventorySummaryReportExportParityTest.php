<?php

namespace Tests\Feature\Livewire\Reports;

use App\Exports\InventorySummaryReportExport;
use App\Services\Reports\InventorySummaryReportFilterData;
use App\Services\Reports\InventorySummaryReportQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\Transaction;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class InventorySummaryReportExportParityTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Category $category;
    private InventorySummaryReportQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $user = \App\Models\User::factory()->create();
        $this->setting = Setting::factory()->create();
        $this->category = Category::create([
            'setting_id' => $this->setting->id,
            'category_code' => 'C1',
            'category_name' => 'Cat1',
            'created_by' => $user->id
        ]);
        \Modules\Setting\Entities\Location::create(["id" => 1, "setting_id" => $this->setting->id, "name" => "Main"]);
        $this->service = new InventorySummaryReportQueryService();
        session(['setting_id' => $this->setting->id]);
    }

    public function test_csv_export_parity()
    {
        Excel::fake();

        $product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'stock_managed' => true,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'product_price' => 100,
            'product_cost' => 100,
        ]);

        Transaction::create([
            'setting_id' => $this->setting->id,
            'product_id' => $product->id,
            'location_id' => 1,
            'type' => 'BUY',
            'current_quantity' => 0,
            'previous_quantity' => 0,
            'previous_quantity_at_location' => 0, 'after_quantity_at_location' => 10, 'quantity_non_tax' => 10, 'quantity_tax' => 0, 'broken_quantity' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0,
            'quantity' => 10,
            'after_quantity' => 10,
            'date' => now()->format('Y-m-d'),
        ]);

        $filters = new InventorySummaryReportFilterData(
            asOfDate: now()
        );

        $result = $this->service->getSummary($filters, $this->setting->id);
        $export = new InventorySummaryReportExport($result['allRows'], $filters, true);
        
        $headings = $export->headings();
        $this->assertEquals([
            'Kode Produk',
            'Nama Produk',
            'Stok di tangan',
            'Batas Minimum',
            'Satuan',
            'Harga Rata-rata',
            'Nilai',
        ], $headings);
        
        $collection = $export->collection();
        $this->assertCount(1, $collection);
        
        $mapped = $export->map($collection->first());
        $this->assertEquals('TEST-001', $mapped[0]);
    }

    public function test_xlsx_export_metadata_and_parity()
    {
        Excel::fake();

        $product = Product::create([
            'setting_id' => $this->setting->id,
            'category_id' => $this->category->id,
            'stock_managed' => true,
            'product_name' => 'Test Product',
            'product_code' => 'TEST-001',
            'product_price' => 100,
            'product_cost' => 100,
        ]);

        Transaction::create([
            'setting_id' => $this->setting->id,
            'product_id' => $product->id,
            'location_id' => 1,
            'type' => 'BUY',
            'current_quantity' => 0,
            'previous_quantity' => 0,
            'previous_quantity_at_location' => 0, 'after_quantity_at_location' => 10, 'quantity_non_tax' => 10, 'quantity_tax' => 0, 'broken_quantity' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity_tax' => 0,
            'quantity' => 10,
            'after_quantity' => 10,
            'date' => now()->format('Y-m-d'),
        ]);

        $filters = new InventorySummaryReportFilterData(
            asOfDate: now()
        );

        $result = $this->service->getSummary($filters, $this->setting->id);
        $export = new InventorySummaryReportExport($result['allRows'], $filters, false);
        
        $headings = $export->headings();
        $this->assertEquals([
            'Kode Produk',
            'Nama Produk',
            'Stok di tangan',
            'Batas Minimum',
            'Satuan',
            'Harga Rata-rata',
            'Nilai',
        ], $headings);
        
        $this->assertEquals('Inventory Summary', $export->title());
        
        $events = $export->registerEvents();
        $this->assertArrayHasKey(\Maatwebsite\Excel\Events\AfterSheet::class, $events);
        
        $closure = $events[\Maatwebsite\Excel\Events\AfterSheet::class];
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $worksheet = $spreadsheet->getActiveSheet();
        $worksheet->setCellValue('A1', 'Kode Produk');
        $sheet = new \Maatwebsite\Excel\Sheet($worksheet);
        $event = new \Maatwebsite\Excel\Events\AfterSheet($sheet, $export);
        
        $closure($event);
        
        $this->assertEquals($this->setting->company_name, $worksheet->getCell('A1')->getValue());
        $this->assertEquals('Ringkasan Persediaan Barang', $worksheet->getCell('A2')->getValue());
        $this->assertStringContainsString('As Of:', $worksheet->getCell('A3')->getValue());
        $this->assertEquals('(dalam IDR)', $worksheet->getCell('A4')->getValue());
        $this->assertEquals('Sorted by: Nama Produk, A-Z', $worksheet->getCell('A5')->getValue());
        
        $this->assertEquals('Total Nilai:', $worksheet->getCell('F8')->getValue());
        $this->assertEquals(0, $worksheet->getCell('G8')->getValue());        
        $collection = $export->collection();
        $this->assertCount(1, $collection);
        
        $mapped = $export->map($collection->first());
        $this->assertEquals('TEST-001', $mapped[0]);
    }
}
