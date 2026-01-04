<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseImportBatch;
use Modules\Purchase\Entities\PurchaseImportRow;
use Modules\Purchase\Services\PurchaseImportService;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchaseImportReproductionTest extends TestCase
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



        
        // Setup User and permissions if needed (bypassed since we test Service directly)
    }

    /** @test */
    public function it_calculates_dpp_and_tax_correctly_for_purchase_694()
    {
        // Data from CSV Line 1240 (Purchase 694)
        // 19/12/2024,Faktur Pembelian,ACJ01/01/FP/2412/AI00013,PT. ANGKASA CERAH JAYA,Lunas,"",22999999.999999,22999999.999999,09/01/2025,20720720.72072,2279279.279279,0.0,"",SURABAYA,"","",CV TIGA NUSA,"",* HP 14S DQ3040TU N4500 8GB 512GB SSD WIN 11 #,"","",5.0,UNIT,4144144.144144,0.00 %,11.0,2279279.279279199999977207207207208,20720720.72072,20720720.72072,0.0,"",0.0,0.0,PT. ANGKASA CERAH JAYA,"",0815 5395 1313,"",0.0,0.0
        
        $rowData = [
            'tanggal' => '19/12/2024',
            'no_faktur' => 'ACJ01/01/FP/2412/AI00013',
            'supplier' => 'PT. ANGKASA CERAH JAYA',
            'produk' => '* HP 14S DQ3040TU N4500 8GB 512GB SSD WIN 11 #',
            'kuantitas' => '5.0',
            'satuan' => 'UNIT',
            'harga_satuan' => '4144144.144144', // DPP from CSV
            'tarif_pajak' => '11.0',
            'diskon_persen' => '0.00 %',
            'tag' => 'CV TIGA NUSA',
            'pajak' => '2279279.279279199999977207207207208',
        ];

        $user = \App\Models\User::factory()->create(['is_active' => 1]);



        $batch = PurchaseImportBatch::create([
            'user_id' => $user->id,
            'source_csv_path' => 'dummy.csv',

            'file_sha256' => 'dummy',
            'status' => PurchaseImportBatch::STATUS_PROCESSING,
        ]);


        $row = PurchaseImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1240,
            'raw_json' => $rowData,
            'status' => PurchaseImportRow::STATUS_PENDING,

        ]);
        
        // Process


        $service = app(PurchaseImportService::class);
        $service->processBatch($batch);



        // Verification
        $purchase = Purchase::where('supplier_purchase_number', 'ACJ01/01/FP/2412/AI00013')->first();
        
        if (!$purchase) {
            $msg = "Row Error: " . PurchaseImportRow::first()->error_message . "\n";
            $msg .= "Batch: " . print_r(PurchaseImportBatch::first()->toArray(), true) . "\n";
            $msg .= "Settings: " . print_r(\Modules\Setting\Entities\Setting::all()->toArray(), true) . "\n";
            file_put_contents(base_path('debug.log'), $msg, FILE_APPEND);
        }




        $this->assertNotNull($purchase, 'Purchase should be created');


        // Check Purchase Totals
        // Expected: Total ~23,000,000
        // Expected: Tax ~2,279,279
        // Expected: DPP ~20,720,720
        
        // Assertions with delta for floating point
        $this->assertEqualsWithDelta(23000000, $purchase->total_amount, 1000, 'Total Amount Mismatch'); // Allow small diff
        $this->assertEqualsWithDelta(20720720.72, $purchase->total_amount - $purchase->tax_amount, 1.0, 'DPP Mismatch');

        // Check Detail
        $detail = $purchase->purchaseDetails->first();
        // user expects Unit Price (DPP) to be ~4,144,144.14
        // BUT actually wants Tax Included: 4,600,000
        $this->assertEqualsWithDelta(4600000, $detail->unit_price, 100.0, 'Detail Unit Price (Tax Included) Mismatch');
        $this->assertEqualsWithDelta(23000000, $detail->sub_total, 100.0, 'Detail Subtotal (Tax Included) Mismatch');
    }
}
