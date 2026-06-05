<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseImportBatch;
use Modules\Purchase\Entities\PurchaseImportRow;
use Modules\Purchase\Services\PurchaseImportService;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Location;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\Transaction;
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

        $cashCoa = \Modules\Setting\Entities\ChartOfAccount::create([
            'account_number' => '1101',
            'name' => 'Cash on Hand',
            'category' => 'Kas & Bank',
            'setting_id' => $this->setting->id,
        ]);

        \Modules\Setting\Entities\PaymentMethod::create([
            'name' => 'CASH',
            'coa_id' => $cashCoa->id,
            'is_cash' => true,
            'requires_reference' => false,
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
        
        // Check is_tax_included
        $this->assertTrue((bool)$purchase->is_tax_included, 'Purchase is_tax_included should be true');
    }

    /** @test */
    public function it_creates_daizu_owned_purchase_for_untagged_kedele_import(): void
    {
        $user = \App\Models\User::factory()->create(['is_active' => 1]);

        // Setup Daizu setting
        $daizuSetting = Setting::create([
            'company_name' => 'DAIZU KEDELAI COMPANY',
            'company_email' => 'daizu@example.com',
            'company_phone' => '123456',
            'company_address' => 'Daizu Address',
            'document_prefix' => 'DK',
            'purchase_prefix_document' => 'PR',
            'default_currency_id' => $this->setting->default_currency_id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'daizu@example.com',
            'footer_text' => 'Daizu Footer',
        ]);

        Location::create([
            'setting_id' => $daizuSetting->id,
            'name' => 'Gudang Barang Daizu',
        ]);

        // Create batch with untagged KEDELE IMPORT row
        $batch = PurchaseImportBatch::create([
            'user_id' => $user->id,
            'source_csv_path' => 'kedele.csv',
            'file_sha256' => 'test_hash',
            'status' => PurchaseImportBatch::STATUS_QUEUED,
        ]);

        PurchaseImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => [
                'tanggal' => '15/05/2026',
                'no_faktur' => 'DK001',
                'supplier' => 'Supplier Kedelai',
                'produk' => 'KEDELE IMPORT',
                'kuantitas' => '100',
                'satuan' => 'KG',
                'harga_satuan' => '5000',
                'tarif_pajak' => '0',
                'diskon_persen' => '0',
                'tag' => '', // Untagged
                'pajak' => '0',
                'sisa_tagihan' => '0',
            ],
            'status' => PurchaseImportRow::STATUS_PENDING,
        ]);

        $service = app(PurchaseImportService::class);
        $service->processBatch($batch);

        $purchase = Purchase::where('supplier_purchase_number', 'DK001')->first();
        $this->assertNotNull($purchase, 'Purchase should be created');
        $this->assertEquals($daizuSetting->id, $purchase->setting_id, 'Purchase should belong to Daizu setting');

        $transaction = Transaction::where('product_id', $purchase->purchaseDetails->first()->product_id)->first();
        $this->assertNull($transaction, 'Transaction should NOT be created for purchase import');
    }

    /** @test */
    public function it_respects_daizu_over_tag_mapping(): void
    {
        $user = \App\Models\User::factory()->create(['is_active' => 1]);

        // Setup Daizu setting
        $daizuSetting = Setting::create([
            'company_name' => 'DAIZU KEDELAI COMPANY',
            'company_email' => 'daizu@example.com',
            'company_phone' => '123456',
            'company_address' => 'Daizu Address',
            'document_prefix' => 'DK',
            'purchase_prefix_document' => 'PR',
            'default_currency_id' => $this->setting->default_currency_id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'daizu@example.com',
            'footer_text' => 'Daizu Footer',
        ]);

        Location::create([
            'setting_id' => $daizuSetting->id,
            'name' => 'Gudang Barang Daizu',
        ]);

        // Create batch with RAGI product but different tag
        $batch = PurchaseImportBatch::create([
            'user_id' => $user->id,
            'source_csv_path' => 'ragi.csv',
            'file_sha256' => 'test_hash2',
            'status' => PurchaseImportBatch::STATUS_QUEUED,
        ]);

        PurchaseImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => [
                'tanggal' => '16/05/2026',
                'no_faktur' => 'DK002',
                'supplier' => 'Supplier Ragi',
                'produk' => 'RAGI PUTIH',
                'kuantitas' => '50',
                'satuan' => 'KG',
                'harga_satuan' => '8000',
                'tarif_pajak' => '0',
                'diskon_persen' => '0',
                'tag' => 'aries', // Different tag mapping
                'pajak' => '0',
                'sisa_tagihan' => '0',
            ],
            'status' => PurchaseImportRow::STATUS_PENDING,
        ]);

        $service = app(PurchaseImportService::class);
        $service->processBatch($batch);

        $purchase = Purchase::where('supplier_purchase_number', 'DK002')->first();
        $this->assertNotNull($purchase, 'Purchase should be created');
        $this->assertEquals($daizuSetting->id, $purchase->setting_id, 'Daizu product should override tag mapping');
    }

    /** @test */
    public function it_fails_when_daizu_setting_missing(): void
    {
        $user = \App\Models\User::factory()->create(['is_active' => 1]);

        // Don't create Daizu setting - test failure case

        $batch = PurchaseImportBatch::create([
            'user_id' => $user->id,
            'source_csv_path' => 'kedele_no_daizu.csv',
            'file_sha256' => 'test_hash3',
            'status' => PurchaseImportBatch::STATUS_QUEUED,
        ]);

        PurchaseImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => [
                'tanggal' => '17/05/2026',
                'no_faktur' => 'DK003',
                'supplier' => 'Supplier Kedelai',
                'produk' => 'KEDELAI PILIHAN',
                'kuantitas' => '75',
                'satuan' => 'KG',
                'harga_satuan' => '6000',
                'tarif_pajak' => '0',
                'diskon_persen' => '0',
                'tag' => '',
                'pajak' => '0',
                'sisa_tagihan' => '0',
            ],
            'status' => PurchaseImportRow::STATUS_PENDING,
        ]);

        $service = app(PurchaseImportService::class);
        $service->processBatch($batch);

        $row = PurchaseImportRow::first();
        $this->assertEquals(PurchaseImportRow::STATUS_INVALID, $row->status, 'Row should be marked invalid');
        $this->assertStringContainsString('Daizu', $row->error_message, 'Error should mention Daizu');
    }

    /** @test */
    public function it_fails_when_daizu_location_missing(): void
    {
        $user = \App\Models\User::factory()->create(['is_active' => 1]);

        // Setup Daizu setting WITHOUT location
        $daizuSetting = Setting::create([
            'company_name' => 'DAIZU KEDELAI COMPANY',
            'company_email' => 'daizu@example.com',
            'company_phone' => '123456',
            'company_address' => 'Daizu Address',
            'default_currency_id' => $this->setting->default_currency_id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'daizu@example.com',
            'footer_text' => 'Daizu Footer',
        ]);

        // Don't create location for Daizu setting

        $batch = PurchaseImportBatch::create([
            'user_id' => $user->id,
            'source_csv_path' => 'kedele_no_location.csv',
            'file_sha256' => 'test_hash4',
            'status' => PurchaseImportBatch::STATUS_QUEUED,
        ]);

        PurchaseImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => [
                'tanggal' => '18/05/2026',
                'no_faktur' => 'DK004',
                'supplier' => 'Supplier Kedelai',
                'produk' => 'KEDELE IMPORT',
                'kuantitas' => '100',
                'satuan' => 'KG',
                'harga_satuan' => '5000',
                'tarif_pajak' => '0',
                'diskon_persen' => '0',
                'tag' => '',
                'pajak' => '0',
                'sisa_tagihan' => '0',
            ],
            'status' => PurchaseImportRow::STATUS_PENDING,
        ]);

        $service = app(PurchaseImportService::class);
        $service->processBatch($batch);

        $row = PurchaseImportRow::first();
        $this->assertEquals(PurchaseImportRow::STATUS_INVALID, $row->status, 'Row should be marked invalid');
        $this->assertStringContainsString('location', strtolower($row->error_message), 'Error should mention location');
    }

    /** @test */
    public function it_resolves_stock_ownership_per_row_in_mixed_product_invoice(): void
    {
        $user = \App\Models\User::factory()->create(['is_active' => 1]);

        // Setup Daizu setting
        $daizuSetting = Setting::create([
            'company_name' => 'DAIZU KEDELAI COMPANY',
            'company_email' => 'daizu@example.com',
            'company_phone' => '123456',
            'company_address' => 'Daizu Address',
            'document_prefix' => 'DK',
            'purchase_prefix_document' => 'PR',
            'default_currency_id' => $this->setting->default_currency_id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'daizu@example.com',
            'footer_text' => 'Daizu Footer',
        ]);

        Location::create([
            'setting_id' => $daizuSetting->id,
            'name' => 'Gudang Daizu',
        ]);

        $batch = PurchaseImportBatch::create([
            'user_id' => $user->id,
            'source_csv_path' => 'mixed.csv',
            'file_sha256' => 'test_hash5',
            'status' => PurchaseImportBatch::STATUS_QUEUED,
        ]);

        // Row 1: Daizu product (KEDELE)
        PurchaseImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => [
                'tanggal' => '19/05/2026',
                'no_faktur' => 'MIX001',
                'supplier' => 'Mixed Supplier',
                'produk' => 'KEDELE IMPORT',
                'kuantitas' => '100',
                'satuan' => 'KG',
                'harga_satuan' => '5000',
                'tarif_pajak' => '0',
                'diskon_persen' => '0',
                'tag' => '',
                'pajak' => '0',
                'sisa_tagihan' => '0',
            ],
            'status' => PurchaseImportRow::STATUS_PENDING,
        ]);

        // Row 2: Non-Daizu product (generic with * marker)
        PurchaseImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 2,
            'raw_json' => [
                'tanggal' => '19/05/2026',
                'no_faktur' => 'MIX001',
                'supplier' => 'Mixed Supplier',
                'produk' => '* Monitor Generic',  // cv tiga nusa tag routes to CV TIGA NUSA
                'kuantitas' => '5',
                'satuan' => 'UNIT',
                'harga_satuan' => '1000000',
                'tarif_pajak' => '0',
                'diskon_persen' => '0',
                'tag' => 'cv tiga nusa',
                'pajak' => '0',
                'sisa_tagihan' => '0',
            ],
            'status' => PurchaseImportRow::STATUS_PENDING,
        ]);

        $service = app(PurchaseImportService::class);
        $service->processBatch($batch);

        // With the grouping fix, Daizu and non-Daizu products should be in SEPARATE purchases
        // Find the Daizu purchase (for KEDELE IMPORT)
        $daizuPurchase = Purchase::where('supplier_purchase_number', 'MIX001')
            ->where('setting_id', $daizuSetting->id)
            ->first();
        $this->assertNotNull($daizuPurchase, 'Daizu purchase should be created for KEDELE IMPORT');
        $this->assertEquals($daizuSetting->id, $daizuPurchase->setting_id, 'Daizu purchase should belong to Daizu setting');

        // Verify KEDELE detail in Daizu purchase
        $kedelaiDetail = $daizuPurchase->purchaseDetails()
            ->whereHas('product', function ($q) {
                $q->where('product_name', 'like', '%KEDELE%');
            })
            ->first();
        $this->assertNotNull($kedelaiDetail, 'Daizu purchase should contain KEDELE IMPORT detail');

        // Check that Daizu product has NO stock movement
        $kedelaiTransaction = Transaction::where('product_id', $kedelaiDetail->product_id)->latest('id')->first();
        $this->assertNull($kedelaiTransaction, 'KEDELE should have NO stock transaction');

        // Find the non-Daizu purchase (for Monitor Generic)
        $normalPurchase = Purchase::where('supplier_purchase_number', 'MIX001')
            ->where('setting_id', '!=', $daizuSetting->id)
            ->first();
        $this->assertNotNull($normalPurchase, 'Non-Daizu purchase should be created for Monitor Generic');

        // Verify Monitor detail in normal purchase
        $monitorDetail = $normalPurchase->purchaseDetails()
            ->whereHas('product', function ($q) {
                $q->where('product_name', 'like', '%Monitor%');
            })
            ->first();
        $this->assertNotNull($monitorDetail, 'Non-Daizu purchase should contain Monitor Generic detail');
    }

    /** @test */
    public function it_creates_separate_purchases_for_mixed_daizu_and_non_daizu_products(): void
    {
        $user = \App\Models\User::factory()->create(['is_active' => 1]);

        // Setup Daizu setting
        $daizuSetting = Setting::create([
            'company_name' => 'DAIZU KEDELAI COMPANY',
            'company_email' => 'daizu@example.com',
            'company_phone' => '123456',
            'company_address' => 'Daizu Address',
            'document_prefix' => 'DK',
            'purchase_prefix_document' => 'PR',
            'default_currency_id' => $this->setting->default_currency_id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'daizu@example.com',
            'footer_text' => 'Daizu Footer',
        ]);

        Location::create([
            'setting_id' => $daizuSetting->id,
            'name' => 'Gudang Daizu',
        ]);

        $batch = PurchaseImportBatch::create([
            'user_id' => $user->id,
            'source_csv_path' => 'mixed_non_daizu_first.csv',
            'file_sha256' => 'test_hash7',
            'status' => PurchaseImportBatch::STATUS_QUEUED,
        ]);

        // Row 1: Non-Daizu product (generic with * marker) - comes FIRST
        PurchaseImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => [
                'tanggal' => '20/05/2026',
                'no_faktur' => 'MIXND001',
                'supplier' => 'Mixed Supplier',
                'produk' => '* Monitor Generic',  // cv tiga nusa tag routes to CV TIGA NUSA
                'kuantitas' => '5',
                'satuan' => 'UNIT',
                'harga_satuan' => '1000000',
                'tarif_pajak' => '0',
                'diskon_persen' => '0',
                'tag' => 'cv tiga nusa',
                'pajak' => '0',
                'sisa_tagihan' => '0',
            ],
            'status' => PurchaseImportRow::STATUS_PENDING,
        ]);

        // Row 2: Daizu product (RAGI) - comes SECOND
        PurchaseImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 2,
            'raw_json' => [
                'tanggal' => '20/05/2026',
                'no_faktur' => 'MIXND001',
                'supplier' => 'Mixed Supplier',
                'produk' => 'RAGI PUTIH',
                'kuantitas' => '50',
                'satuan' => 'KG',
                'harga_satuan' => '8000',
                'tarif_pajak' => '0',
                'diskon_persen' => '0',
                'tag' => '',
                'pajak' => '0',
                'sisa_tagihan' => '0',
            ],
            'status' => PurchaseImportRow::STATUS_PENDING,
        ]);

        $service = app(PurchaseImportService::class);
        $service->processBatch($batch);

        // With the fix, these should be in SEPARATE purchases:
        // - One for Monitor Generic (owned by CV TIGA NUSA or default)
        // - One for RAGI PUTIH (owned by Daizu)

        // Find the Daizu purchase
        $daizuPurchase = Purchase::where('setting_id', $daizuSetting->id)
            ->where('supplier_purchase_number', 'MIXND001')
            ->first();
        $this->assertNotNull($daizuPurchase, 'Daizu purchase should be created for RAGI PUTIH');
        $this->assertEquals($daizuSetting->id, $daizuPurchase->setting_id, 'Daizu purchase should belong to Daizu setting');

        // Verify RAGI detail in Daizu purchase
        $ragiDetail = $daizuPurchase->purchaseDetails()
            ->whereHas('product', function ($q) {
                $q->where('product_name', 'like', '%RAGI%');
            })
            ->first();
        $this->assertNotNull($ragiDetail, 'Daizu purchase should contain RAGI PUTIH detail');

        // Find the non-Daizu purchase (should belong to CV TIGA NUSA since Monitor has no marker)
        $normalPurchase = Purchase::where('supplier_purchase_number', 'MIXND001')
            ->where('setting_id', '!=', $daizuSetting->id)
            ->first();
        $this->assertNotNull($normalPurchase, 'Non-Daizu purchase should be created for Monitor Generic');

        // Verify Monitor detail in normal purchase
        $monitorDetail = $normalPurchase->purchaseDetails()
            ->whereHas('product', function ($q) {
                $q->where('product_name', 'like', '%Monitor%');
            })
            ->first();
        $this->assertNotNull($monitorDetail, 'Non-Daizu purchase should contain Monitor Generic detail');
    }

    /** @test */
    public function it_counts_skipped_duplicate_rows_as_processed(): void
    {
        $user = \App\Models\User::factory()->create(['is_active' => 1]);

        // Create a supplier first
        $supplier = \Modules\People\Entities\Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_email' => 'supplier@test.com',
            'supplier_phone' => '123456',
            'address' => 'Test Address',
            'city' => 'Test City',
            'country' => 'Test Country',
            'setting_id' => $this->setting->id,
        ]);

        // Create existing purchase with required fields (using CV TIGA NUSA since * maps to it)
        $tigaNusa = Setting::where('company_name', 'LIKE', '%CV TIGA NUSA%')->first();
        $existingPurchase = Purchase::create([
            'date' => now(),
            'due_date' => now(),
            'reference' => 'DUP001',
            'supplier_id' => $supplier->id,
            'supplier_name' => 'Test Supplier',
            'setting_id' => $tigaNusa ? $tigaNusa->id : $this->setting->id,
            'status' => Purchase::STATUS_RECEIVED,
            'supplier_purchase_number' => 'DUP-INVOICE-001',
            'total_amount' => 10000,
            'paid_amount' => 0,
            'due_amount' => 10000,
            'payment_status' => 'UNPAID',
            'payment_method' => '',
        ]);

        $batch = PurchaseImportBatch::create([
            'user_id' => $user->id,
            'source_csv_path' => 'duplicate.csv',
            'file_sha256' => 'test_hash6',
            'status' => PurchaseImportBatch::STATUS_QUEUED,
        ]);

        // Try to import duplicate row with asterisk prefix (routes to CV TIGA NUSA)
        PurchaseImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => [
                'tanggal' => '20/05/2026',
                'no_faktur' => 'DUP-INVOICE-001',
                'supplier' => 'Duplicate Supplier',
                'produk' => '* Generic Product',
                'kuantitas' => '10',
                'satuan' => 'UNIT',
                'harga_satuan' => '1000',
                'tarif_pajak' => '0',
                'diskon_persen' => '0',
                'tag' => 'cv tiga nusa',
                'pajak' => '0',
                'sisa_tagihan' => '0',
            ],
            'status' => PurchaseImportRow::STATUS_PENDING,
        ]);

        $service = app(PurchaseImportService::class);
        $service->processBatch($batch);

        $batch->refresh();
        $row = PurchaseImportRow::first();

        $this->assertEquals(PurchaseImportRow::STATUS_SKIPPED, $row->status, 'Row should be skipped');
        $this->assertEquals(0, $batch->success_count, 'Success count should be 0 for duplicate');
        $this->assertEquals(1, $batch->processed_rows, 'Processed rows should include the skipped duplicate row');
        // Verify the batch processed count includes skipped rows (regression check)
        $processedCount = $batch->rows()->whereIn('status', ['processed', 'invalid', 'skipped'])->count();
        $this->assertEquals($batch->processed_rows, $processedCount, 'Persisted processed_rows should match row count');
    }
}
