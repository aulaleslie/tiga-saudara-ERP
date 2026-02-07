<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalesImportBatch;
use Modules\Sale\Entities\SalesImportRow;
use Modules\Sale\Services\SalesImportService;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SalesImportReproductionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Setup Currency
        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        // Setup Setting
        $this->setting = Setting::create([
            'company_name' => 'CV TIGA NUSA COMPUTER',
            'company_email' => 'test@example.com',
            'company_phone' => '123456',
            'company_address' => 'Test Address',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'test@example.com',
            'footer_text' => 'Test Footer',
        ]);

        // Setup Location
        \Modules\Setting\Entities\Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Test Location',
        ]);
    }

    /** @test */
    public function it_calculates_tax_included_price_correctly()
    {
       // Test Data derived from Sales-2024-Q4.csv (Line 2)
       // 01/10/2024,Faktur Penjualan,JL.2024.23187,FADLI H GRUP... ,2032000.0, ... 
       // Produk: CCTV CAM DAHUA... | Qty: 4 | Harga Satuan (DPP?): 800000 / 4 = 200,000 ??
       // Actually let's use a simpler theoretical case to be sure.
       // Let's say:
       // Harga Satuan (Entry): 100,000 (DPP)
       // Tax: 11%
       // Tax Amount: 11,000
       // Total per line: 111,000
       
       // If we want Tax Included to be stored:
       // unit_price should be 111,000
       // sub_total should be 111,000 * Qty
       
       $rowData = [
            'tanggal' => '01/10/2024',
            'no_faktur' => 'INV-TEST-001',
            'customer' => 'TEST CUSTOMER',
            'produk' => 'TEST PRODUCT',
            'kuantitas' => '2',
            'satuan' => 'PCS',
            'harga_satuan' => '100000', // DPP
            'tarif_pajak' => '11.0',
            'pajak' => '22000', // 11% of 200,000
            'tag' => 'CV TIGA NUSA', 
       ];

        $user = \App\Models\User::factory()->create(['is_active' => 1]);

        $batch = SalesImportBatch::create([
            'user_id' => $user->id,
            'source_csv_path' => 'dummy.csv',
            'file_sha256' => 'dummy',
            'status' => SalesImportBatch::STATUS_PROCESSING,
        ]);

        $row = SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 2, // Arbitrary
            'raw_json' => $rowData,
        ]);
        
        $service = app(SalesImportService::class);
        $service->processBatch($batch);

        // Verification
        $sale = Sale::where('imported_sales_reference_number', 'INV-TEST-001')->first();
        
        if (!$sale) {
             $row = SalesImportRow::first();
             dump($row->status, $row->error_message);
        }

        $this->assertNotNull($sale, 'Sale should be created');

        $detail = $sale->saleDetails->first();
        
        // Current Logic likely stores 100,000 (DPP).
        // Target Logic should store 111,000 (Tax Included).
        
        // Asserting Target Logic immediately to fail first
        $this->assertEqualsWithDelta(111000, $detail->unit_price, 1.0, 'Detail Unit Price (Tax Included) Mismatch');
        $this->assertEqualsWithDelta(222000, $detail->sub_total, 1.0, 'Detail Subtotal (Tax Included) Mismatch');
        
        // Also verify tax amount is stored correctly
        $this->assertEqualsWithDelta(22000, $detail->product_tax_amount, 1.0, 'Tax Amount Mismatch');
    }
}
