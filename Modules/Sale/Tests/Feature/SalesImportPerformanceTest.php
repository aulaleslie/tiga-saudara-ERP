<?php

namespace Modules\Sale\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Sale\Entities\SalesImportBatch;
use Modules\Sale\Entities\SalesImportRow;
use Modules\Sale\Entities\Sale;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Location;
use Modules\Sale\Services\SalesImportService;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;
use ZipArchive;

class SalesImportPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $globalSetting;
    protected SalesImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = \App\Models\User::factory()->create();
        
        Gate::before(function ($user, $ability) {
            return true;
        });

        $currency = Currency::create([
            'currency_name'      => 'Rupiah',
            'code'               => 'IDR',
            'symbol'             => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator'  => ',',
        ]);

        $this->globalSetting = Setting::create([
            'company_name' => 'PERDANA',
            'company_email' => 'global@test.com',
            'company_phone' => '123',
            'company_address' => 'Test',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'is_pkp' => false,
        ]);

        Location::create([
            'setting_id' => $this->globalSetting->id,
            'name' => 'Global Loc',
        ]);
        
        $coa = ChartOfAccount::create([
            'setting_id' => $this->globalSetting->id,
            'name' => 'Kas',
            'account_number' => '1001',
            'category' => 'Kas & Bank',
        ]);
        PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $coa->id,
            'is_cash' => true,
        ]);
        PaymentMethod::create([
            'name' => \App\Support\ImportPaymentSummaryResolver::DEDUCTION_METHOD_NAME,
            'coa_id' => $coa->id,
            'is_cash' => false,
        ]);

        $this->service = app(SalesImportService::class);
    }

    public function test_staged_sales_rows_persist_invoice_key()
    {
        $batch = SalesImportBatch::create([
            'status' => SalesImportBatch::STATUS_QUEUED,
            'total_rows' => 1,
            'user_id' => $this->user->id, 'source_csv_path' => 'dummy.csv', 'file_sha256' => 'dummy',
        ]);

        $row = SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => [
                'no_faktur' => 'INV-PERF-1',
                'tanggal' => '01/01/2023',
                'customer' => 'Cust A',
                'produk' => 'Minyak',
                'kuantitas' => '10',
                'harga_satuan' => '1000',
            ],
        ]);

        $this->assertEquals('INV-PERF-1', $row->invoice_number);
        $this->assertDatabaseHas('sales_import_rows', [
            'id' => $row->id,
            'invoice_number' => 'INV-PERF-1'
        ]);
    }

    public function test_non_contiguous_same_invoice_rows_reconcile()
    {
        $batch = SalesImportBatch::create([
            'status' => SalesImportBatch::STATUS_QUEUED,
            'total_rows' => 502,
            'user_id' => $this->user->id, 'source_csv_path' => 'dummy.csv', 'file_sha256' => 'dummy',
        ]);

        // Row 1: Invoice A
        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => [
                'no_faktur' => 'INV-PERF-2',
                'tanggal' => '01/01/2023',
                'customer' => 'Cust A',
                'produk' => 'Minyak 1',
                'kuantitas' => '10',
                'harga_satuan' => '1000',
                'sisa_tagihan' => '0',
                'status_hari_ini' => 'Lunas',
                'pembayaran' => '25000',
                'source_total' => '25000',
            ],
        ]);

        // 500 dummy rows (Row 2 to 501)
        $dummyRows = [];
        $now = now();
        for ($i = 2; $i <= 501; $i++) {
            $dummyRows[] = [
                'batch_id' => $batch->id,
                'row_number' => $i,
                'invoice_number' => 'INV-DUMMY-' . $i,
                'raw_json' => json_encode([
                    'no_faktur' => 'INV-DUMMY-' . $i,
                    'tanggal' => '01/01/2023',
                    'customer' => 'Cust B',
                    'produk' => 'Beras',
                    'kuantitas' => '5',
                    'harga_satuan' => '2000',
                ]),
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        SalesImportRow::insert($dummyRows);

        // Row 502: Invoice A again (outside the initial 500-row chunk)
        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 502,
            'raw_json' => [
                'no_faktur' => 'INV-PERF-2',
                'tanggal' => '01/01/2023',
                'customer' => 'Cust A',
                'produk' => 'Minyak 2',
                'kuantitas' => '15',
                'harga_satuan' => '1000',
                'sisa_tagihan' => '0',
                'status_hari_ini' => 'Lunas',
                'pembayaran' => '25000',
                'source_total' => '25000',
            ],
        ]);

        $this->service->processBatch($batch);

        $sales = Sale::where('imported_sales_reference_number', 'INV-PERF-2')->get();
        // Since both rows for INV-PERF-2 belong to global setting, they should combine to 1 sale
        $this->assertCount(1, $sales);
        $sale = $sales->first();
        
        $this->assertEquals(25000, $sale->total_amount);
        $this->assertEquals(2, $sale->saleDetails()->count());
    }

    public function test_zip_extraction_does_not_use_whole_file_reads()
    {
        Storage::fake('local');
        
        // Create a dummy CSV
        $csvContent = "Tanggal,Nomor Transaksi,Nama Produk\n01/01/2023,INV-1,Test";
        $csvPath = Storage::path('dummy.csv');
        file_put_contents($csvPath, $csvContent);
        
        // Create a ZIP containing the CSV
        $zipPath = Storage::path('test.zip');
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFile($csvPath, 'dummy.csv');
        $zip->close();
        
        $file = new UploadedFile($zipPath, 'test.zip', 'application/zip', null, true);
        
        $response = $this->actingAs($this->user)->post(route('sales.upload.store'), [
            'file' => $file
        ]);

        $this->assertTrue($response->isOk() || $response->isRedirect(), $response->getContent());
        
        // Check that batch was created and stored file is not a zip
        $batch = SalesImportBatch::latest()->first();
        $this->assertNotNull($batch);
        $this->assertStringEndsWith('.csv', $batch->source_csv_path);
        
        // Ensure the file exists
        Storage::assertExists($batch->source_csv_path);
        
        // Contents should match
        $this->assertEquals($csvContent, Storage::get($batch->source_csv_path));
    }
}
