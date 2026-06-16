<?php

namespace Modules\Sale\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sale\Entities\SalesImportBatch;
use Modules\Sale\Entities\SalesImportRow;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SaleDetail;
use Modules\Sale\Entities\Payment;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Currency\Entities\Currency;
use Modules\Sale\Services\SalesImportService;
use Modules\Product\Entities\Product;
use Tests\TestCase;

class SalesImportProductSplitNoStockTest extends TestCase
{
    use RefreshDatabase;

    protected Setting $globalSetting;
    protected Setting $daizuSetting;
    protected Setting $otherSetting;
    protected Setting $topItSetting;
    protected SalesImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = \App\Models\User::factory()->create();

        $currency = Currency::create([
            'currency_name'      => 'Rupiah',
            'code'               => 'IDR',
            'symbol'             => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator'  => ',',
        ]);

        // Global Setting (is_pkp = false)
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
        Location::create(['setting_id' => $this->globalSetting->id, 'name' => 'Global Loc']);

        // Daizu Setting (is_pkp = true)
        $this->daizuSetting = Setting::create([
            'company_name' => 'DAIZU CORP',
            'company_email' => 'daizu@test.com',
            'company_phone' => '123',
            'company_address' => 'Test',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'is_pkp' => false,
        ]);
        Location::create(['setting_id' => $this->daizuSetting->id, 'name' => 'Gudang Utama']);

        // Other Setting (is_pkp = false)
        $this->otherSetting = Setting::create([
            'company_name' => 'CV TIGA NUSA COMPUTER',
            'company_email' => 'other@test.com',
            'company_phone' => '123',
            'company_address' => 'Test',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'is_pkp' => true,
        ]);
        Location::create(['setting_id' => $this->otherSetting->id, 'name' => 'Other Loc']);

        // Top IT Setting (is_pkp = false)
        $this->topItSetting = Setting::create([
            'company_name' => 'CV TOP IT INTERNUSA',
            'company_email' => 'topit@test.com',
            'company_phone' => '123',
            'company_address' => 'Test',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'is_pkp' => false,
        ]);
        Location::create(['setting_id' => $this->topItSetting->id, 'name' => 'Top IT Loc']);

        // Configure Daizu
        config([
            'daizu.enabled' => true,
            'daizu.company_name' => 'DAIZU CORP',
            'daizu.products' => ['Daizu Kedelai'],
            'daizu.gudang_mapping' => [
                'Gudang Utama' => 'Daizu Loc'
            ],
            'daizu.product_owner_markers' => [
                'CV TIGA NUSA COMPUTER' => 'asterisk',
                'CV TOP IT INTERNUSA' => 'tp'
            ],
            'daizu.global_fallback_owner' => 'PERDANA'
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

        // Deduction Method
        PaymentMethod::create([
            'name' => \App\Support\ImportPaymentSummaryResolver::DEDUCTION_METHOD_NAME,
            'coa_id' => $coa->id,
            'is_cash' => false,
        ]);

        $this->service = app(SalesImportService::class);
    }

    public function test_tag_no_longer_affects_non_daizu_ownership_and_is_synced_as_metadata()
    {
        $batch = SalesImportBatch::create([
            'status' => SalesImportBatch::STATUS_QUEUED,
            'total_rows' => 1,
            'user_id' => $this->user->id, 'source_csv_path' => 'dummy.csv', 'file_sha256' => 'dummy',
        ]);

        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => [
                'no_faktur' => 'INV-001',
                'tanggal' => '01/01/2023',
                'customer' => 'Cust A',
                'produk' => 'Beras Lokal',
                'kuantitas' => '10',
                'harga_satuan' => '1000',
                'tag' => 'CV TIGA NUSA COMPUTER', // Tag should be ignored for ownership
            ],
        ]);

        $this->service->processBatch($batch);

        $sale = Sale::where('imported_sales_reference_number', 'INV-001')->first();
        $this->assertNotNull($sale);

        // Should fallback to global because 'Beras Lokal' has no marker
        $this->assertEquals($this->globalSetting->id, $sale->setting_id);

        // Tag metadata is still present
        $this->assertTrue($sale->tags->contains(function ($t) {
            return strpos($t->name, 'CV TIGA NUSA COMPUTER') !== false;
        }));
    }

    public function test_daizu_products_route_to_daizu_tenant_regardless_of_tag()
    {
        $batch = SalesImportBatch::create([
            'status' => SalesImportBatch::STATUS_QUEUED,
            'total_rows' => 1,
            'user_id' => $this->user->id, 'source_csv_path' => 'dummy.csv', 'file_sha256' => 'dummy',
        ]);

        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => [
                'no_faktur' => 'INV-002',
                'tanggal' => '01/01/2023',
                'customer' => 'Cust A',
                'produk' => 'Daizu Kedelai',
                'gudang' => 'Gudang Utama',
                'kuantitas' => '10',
                'harga_satuan' => '1000',
                'tag' => 'IGNORE ME',
            ],
        ]);

        $this->service->processBatch($batch);

        $sale = Sale::where('imported_sales_reference_number', 'INV-002')->first();
        $this->assertNotNull($sale);
        $this->assertEquals($this->daizuSetting->id, $sale->setting_id);
    }

    public function test_mixed_product_invoices_split_correctly()
    {
        $batch = SalesImportBatch::create([
            'status' => SalesImportBatch::STATUS_QUEUED,
            'total_rows' => 4,
            'user_id' => $this->user->id, 'source_csv_path' => 'dummy.csv', 'file_sha256' => 'dummy',
        ]);

        // Same invoice, 3 different owner products
        $common = [
            'no_faktur' => 'MIX-100',
            'tanggal' => '01/01/2023',
            'customer' => 'Cust Mixed',
            'sisa_tagihan' => '0',
            'status_hari_ini' => 'Lunas',
        ];

        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => array_merge($common, [
                'produk' => 'Daizu Kedelai', // Daizu -> daizuSetting
                'gudang' => 'Gudang Utama',
                'kuantitas' => '10',
                'harga_satuan' => '100',
            ]),
        ]);

        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => array_merge($common, [
                'produk' => '* Beras', // asterisk -> otherSetting
                'kuantitas' => '5',
                'harga_satuan' => '200',
            ]),
        ]);

        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => array_merge($common, [
                'produk' => 'Minyak', // no marker -> globalSetting
                'kuantitas' => '2',
                'harga_satuan' => '500',
            ]),
        ]);

        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => array_merge($common, [
                'produk' => 'Mouse TP', // tp -> topItSetting
                'kuantitas' => '4',
                'harga_satuan' => '250',
            ]),
        ]);

        $this->service->processBatch($batch);

        $sales = Sale::where('imported_sales_reference_number', 'MIX-100')->get();
        $this->assertCount(4, $sales);

        $settings = $sales->pluck('setting_id')->toArray();
        $this->assertContains($this->daizuSetting->id, $settings);
        $this->assertContains($this->otherSetting->id, $settings);
        $this->assertContains($this->globalSetting->id, $settings);
        $this->assertContains($this->topItSetting->id, $settings);

        $totalSum = $sales->sum('total_amount');
        $this->assertEquals(4000, $totalSum);
    }

    public function test_non_pkp_owners_persist_csv_tax_regardless_of_is_pkp()
    {
        $batch = SalesImportBatch::create([
            'status' => SalesImportBatch::STATUS_QUEUED,
            'total_rows' => 1,
            'user_id' => $this->user->id, 'source_csv_path' => 'dummy.csv', 'file_sha256' => 'dummy',
        ]);

        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => [
                'no_faktur' => 'INV-NONPKP',
                'tanggal' => '01/01/2023',
                'customer' => 'Cust A',
                'produk' => 'Minyak', // global setting, is_pkp = false
                'kuantitas' => '10',
                'harga_satuan' => '1000',
                'pajak' => '500', // CSV says 500 tax
                'tarif_pajak' => '5%',
                'sisa_tagihan' => '0',
                'status_hari_ini' => 'Lunas',
            ],
        ]);

        $this->service->processBatch($batch);

        $sale = Sale::where('imported_sales_reference_number', 'INV-NONPKP')->first();
        $this->assertEquals(10500, $sale->total_amount); // 10 * 1000 + 500 tax
        $this->assertEquals(500, $sale->tax_amount);
        $this->assertEquals(5, $sale->tax_percentage);

        $detail = $sale->saleDetails->first();
        $this->assertEquals(500, $detail->product_tax_amount);
        $this->assertNotNull($detail->tax_id);
    }

    public function test_pkp_owners_persist_csv_tax_correctly()
    {
        $batch = SalesImportBatch::create([
            'status' => SalesImportBatch::STATUS_QUEUED,
            'total_rows' => 1,
            'user_id' => $this->user->id, 'source_csv_path' => 'dummy.csv', 'file_sha256' => 'dummy',
        ]);

        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => [
                'no_faktur' => 'INV-PKP',
                'tanggal' => '01/01/2023',
                'customer' => 'Cust A',
                'produk' => '* Beras', // * marker -> Tiga Nusa setting, is_pkp = true
                'kuantitas' => '10',
                'harga_satuan' => '1000',
                'pajak' => '1100', // 11% tax
                'tarif_pajak' => '11%',
                'sisa_tagihan' => '0',
                'status_hari_ini' => 'Lunas',
            ],
        ]);

        $this->service->processBatch($batch);

        $sale = Sale::where('imported_sales_reference_number', 'INV-PKP')->first();
        $this->assertEquals(11100, $sale->total_amount); // 10000 + 1100
        $this->assertEquals(1100, $sale->tax_amount);
        $this->assertEquals(11, $sale->tax_percentage);

        $detail = $sale->saleDetails->first();
        $this->assertEquals(1100, $detail->product_tax_amount);
        $this->assertNotNull($detail->tax_id);
    }

    public function test_no_inventory_mutation_on_dispatch()
    {
        $batch = SalesImportBatch::create([
            'status' => SalesImportBatch::STATUS_QUEUED,
            'total_rows' => 1,
            'user_id' => $this->user->id, 'source_csv_path' => 'dummy.csv', 'file_sha256' => 'dummy',
        ]);

        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => [
                'no_faktur' => 'INV-DISPATCH',
                'tanggal' => '01/01/2023',
                'customer' => 'Cust A',
                'produk' => 'Minyak',
                'kuantitas' => '10',
                'harga_satuan' => '1000',
            ],
        ]);

        // Ensure no initial transactions
        $initialTransactions = \Modules\Product\Entities\Transaction::count();

        $this->service->processBatch($batch);

        $sale = Sale::where('imported_sales_reference_number', 'INV-DISPATCH')->first();
        $this->assertEquals(\Modules\Sale\Entities\Sale::STATUS_DISPATCHED, $sale->status);
        $dispatch = $sale->saleDispatches->first();
        $this->assertNotNull($dispatch);
        $this->assertCount(1, $dispatch->details);

        // Transactions should NOT have increased because inventory mutation is skipped
        $finalTransactions = \Modules\Product\Entities\Transaction::count();
        $this->assertEquals($initialTransactions, $finalTransactions);
    }

    public function test_document_level_adjustments_and_drift_allocated_pro_rata()
    {
        $batch = SalesImportBatch::create([
            'status' => SalesImportBatch::STATUS_QUEUED,
            'total_rows' => 2,
            'user_id' => $this->user->id, 'source_csv_path' => 'dummy.csv', 'file_sha256' => 'dummy',
        ]);

        // Source total = (Daizu 1000 + Other 3000) = 4000
        // Document Discount = 400 -> Daizu -100, Other -300
        // Expected Daizu total = 900
        // Expected Other total = 2700
        // Sum = 3600

        $common = [
            'no_faktur' => 'MIX-DISCOUNT',
            'tanggal' => '01/01/2023',
            'customer' => 'Cust Mixed',
            'diskon' => '400',
            'sisa_tagihan' => '0',
            'status_hari_ini' => 'Lunas',
            'pembayaran' => '3600',
            'source_total' => '3600',
        ];

        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => array_merge($common, [
                'produk' => 'Daizu Kedelai',
                'gudang' => 'Gudang Utama',
                'kuantitas' => '10',
                'harga_satuan' => '100', // 1000 gross
            ]),
        ]);

        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => array_merge($common, [
                'produk' => '* Beras',
                'kuantitas' => '10',
                'harga_satuan' => '300', // 3000 gross
            ]),
        ]);

        $this->service->processBatch($batch);

        $daizuSale = Sale::where('imported_sales_reference_number', 'MIX-DISCOUNT')
            ->where('setting_id', $this->daizuSetting->id)->first();
        $otherSale = Sale::where('imported_sales_reference_number', 'MIX-DISCOUNT')
            ->where('setting_id', $this->otherSetting->id)->first();

        $this->assertEquals(900, $daizuSale->total_amount);
        $this->assertEquals(100, $daizuSale->discount_amount);
        $this->assertEquals(900, $daizuSale->paid_amount);

        $this->assertEquals(2700, $otherSale->total_amount);
        $this->assertEquals(300, $otherSale->discount_amount);
        $this->assertEquals(2700, $otherSale->paid_amount);
    }

    public function test_accepted_source_total_precision_drift_is_allocated_pro_rata()
    {
        $batch = SalesImportBatch::create([
            'status' => SalesImportBatch::STATUS_QUEUED,
            'total_rows' => 2,
            'user_id' => $this->user->id, 'source_csv_path' => 'dummy.csv', 'file_sha256' => 'dummy',
        ]);

        $common = [
            'no_faktur' => 'MIX-DRIFT',
            'tanggal' => '01/01/2023',
            'customer' => 'Cust Mixed',
            'diskon' => '0',
            'sisa_tagihan' => '0',
            'status_hari_ini' => 'Lunas',
            'pembayaran' => '4000.01',
            'source_total' => '4000.01',
        ];

        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => array_merge($common, [
                'produk' => 'Daizu Kedelai',
                'gudang' => 'Gudang Utama',
                'kuantitas' => '10',
                'harga_satuan' => '100', // 1000 gross
            ]),
        ]);

        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => array_merge($common, [
                'produk' => '* Beras',
                'kuantitas' => '10',
                'harga_satuan' => '300', // 3000 gross
            ]),
        ]);

        $this->service->processBatch($batch);

        $daizuSale = Sale::where('imported_sales_reference_number', 'MIX-DRIFT')
            ->where('setting_id', $this->daizuSetting->id)->first();
        $otherSale = Sale::where('imported_sales_reference_number', 'MIX-DRIFT')
            ->where('setting_id', $this->otherSetting->id)->first();

        // 4000.01 -> 1000 and 3000
        // allocation of 0.01 to the largest which is 3000
        $this->assertEquals(1000, $daizuSale->total_amount);
        $this->assertEquals(1000, $daizuSale->paid_amount);

        $this->assertEquals(3000.01, $otherSale->total_amount);
        $this->assertEquals(3000.01, $otherSale->paid_amount);
    }

    public function test_jl_2025_14721_shape_split_owner_repeated_discount_exact_reconciliation()
    {
        $batch = SalesImportBatch::create([
            'status' => SalesImportBatch::STATUS_QUEUED,
            'total_rows' => 4,
            'user_id' => $this->user->id, 'source_csv_path' => 'dummy.csv', 'file_sha256' => 'dummy',
        ]);

        $common = [
            'no_faktur' => 'JL.2025.14721',
            'tanggal' => '02/08/2025',
            'customer' => 'YADIN TANGGA',
            'diskon' => '77857.316776',
            'sisa_tagihan' => '0',
            'status_hari_ini' => 'Lunas',
            'pembayaran' => '1400000.0',
            'source_total' => '1400000.0',
        ];

        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => array_merge($common, [
                'produk' => '* AUTO SHEET FEEDER FOR G1010 / G2010', // * marker -> otherSetting (Tiga Nusa)
                'kuantitas' => '1.0',
                'harga_satuan' => '360360.36036',
                'pajak' => '37496.9585585210599996250304144147894',
                'tarif_pajak' => '11.0',
            ]),
        ]);

        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 2,
            'raw_json' => array_merge($common, [
                'produk' => 'SERVICE', // no marker -> globalSetting
                'kuantitas' => '1.0',
                'harga_satuan' => '50000.0',
                'pajak' => '0.0',
            ]),
        ]);

        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 3,
            'raw_json' => array_merge($common, [
                'produk' => 'SERVICE', // no marker -> globalSetting
                'kuantitas' => '1.0',
                'harga_satuan' => '30000.0',
                'pajak' => '0.0',
            ]),
        ]);

        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 4,
            'raw_json' => array_merge($common, [
                'produk' => 'PRINT HEAD CATRIDGE BH 70 CANON G1020', // no marker -> globalSetting
                'kuantitas' => '2.0',
                'harga_satuan' => '500000.0',
                'pajak' => '0.0',
            ]),
        ]);

        $this->service->processBatch($batch);

        $sales = Sale::where('imported_sales_reference_number', 'JL.2025.14721')->get();
        $this->assertCount(2, $sales);

        $otherSale = $sales->where('setting_id', $this->otherSetting->id)->first();
        $globalSale = $sales->where('setting_id', $this->globalSetting->id)->first();

        $this->assertNotNull($otherSale, 'Asterisk product must create sale in otherSetting');
        $this->assertNotNull($globalSale, 'Unmarked products must create sale in globalSetting');

        // Total gross = 1,477,857.318918521
        // Other gross = 397,857.318918521
        // Global gross = 1,080,000.0
        // Discount = 77,857.316776
        // Sum of owner canonical totals must equal 1,400,000.0
        $this->assertEquals(1400000.0, round($otherSale->total_amount + $globalSale->total_amount, 2));

        // Verify each sale is marked as paid with no outstanding amount
        $this->assertEquals('PAID', $otherSale->payment_status);
        $this->assertEquals('PAID', $globalSale->payment_status);
        $this->assertEquals(0.0, $otherSale->due_amount);
        $this->assertEquals(0.0, $globalSale->due_amount);
        $this->assertEquals($otherSale->total_amount, $otherSale->paid_amount);
        $this->assertEquals($globalSale->total_amount, $globalSale->paid_amount);

        // Verify no invalid rows in the batch
        $invalidRows = $batch->rows()->where('status', 'invalid')->get();
        $this->assertCount(0, $invalidRows, 'No rows should be marked invalid');

        // Verify all 4 rows were processed
        $processedRows = $batch->rows()->whereIn('status', ['processed', 'skipped'])->count();
        $this->assertEquals(4, $processedRows, 'All rows must be processed or skipped');

        // Verify payment rows exist and account for all paid amount
        $otherPayments = $otherSale->salePayments()->get();
        $globalPayments = $globalSale->salePayments()->get();
        $this->assertGreaterThan(0, $otherPayments->count(), 'Split owner sale should have payment rows');
        $this->assertGreaterThan(0, $globalPayments->count(), 'Global owner sale should have payment rows');

        // Verify payment row amounts sum to paid amounts (including cash and deductions)
        $otherPaidByPaymentRows = $otherPayments->sum('amount');
        $globalPaidByPaymentRows = $globalPayments->sum('amount');
        $this->assertEquals($otherSale->paid_amount, $otherPaidByPaymentRows);
        $this->assertEquals($globalSale->paid_amount, $globalPaidByPaymentRows);
    }

    public function test_jl_2025_24026_shape_fractional_discount_allocation_no_over_settlement()
    {
        $batch = SalesImportBatch::create([
            'status' => SalesImportBatch::STATUS_QUEUED,
            'total_rows' => 4,
            'user_id' => $this->user->id, 'source_csv_path' => 'dummy.csv', 'file_sha256' => 'dummy',
        ]);

        $common = [
            'no_faktur' => 'JL.2025.24026',
            'tanggal' => '13/11/2025',
            'customer' => 'CASH',
            'diskon' => '2366.383237',
            'sisa_tagihan' => '0',
            'status_hari_ini' => 'Lunas',
            'pembayaran' => '220000.0',
            'source_total' => '220000.0',
        ];

        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => array_merge($common, [
                'produk' => '* TINTA CANON ORI 790 BLACK',
                'kuantitas' => '1.0',
                'harga_satuan' => '108108.108108',
                'pajak' => '11758.2745945828399998824172540541716',
                'tarif_pajak' => '11.0',
            ]),
        ]);

        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 2,
            'raw_json' => array_merge($common, [
                'produk' => 'BLUEPRINT CANON BLACK (100ML)',
                'kuantitas' => '1.0',
                'harga_satuan' => '40000.0',
                'pajak' => '0.0',
            ]),
        ]);

        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 3,
            'raw_json' => array_merge($common, [
                'produk' => 'COOLINGPAD NC 32 1FAN BESAR',
                'kuantitas' => '1.0',
                'harga_satuan' => '45000.0',
                'pajak' => '0.0',
            ]),
        ]);

        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 4,
            'raw_json' => array_merge($common, [
                'produk' => 'PLASTIK KLIP 5 X 8',
                'kuantitas' => '5.0',
                'harga_satuan' => '3500.0',
                'pajak' => '0.0',
            ]),
        ]);

        $this->service->processBatch($batch);

        $sales = Sale::where('imported_sales_reference_number', 'JL.2025.24026')->get();
        $this->assertCount(2, $sales);

        $otherSale = $sales->where('setting_id', $this->otherSetting->id)->first();
        $globalSale = $sales->where('setting_id', $this->globalSetting->id)->first();

        $this->assertNotNull($otherSale, 'Asterisk product must create sale in otherSetting');
        $this->assertNotNull($globalSale, 'Unmarked products must create sale in globalSetting');

        // Ensure no over-settlement exception was thrown and totals exactly match the source_total of 220000.0
        $this->assertEquals(220000.0, round($otherSale->total_amount + $globalSale->total_amount, 2));

        // Verify each sale is marked as paid with no outstanding amount
        $this->assertEquals('PAID', $otherSale->payment_status);
        $this->assertEquals('PAID', $globalSale->payment_status);
        $this->assertEquals(0.0, $otherSale->due_amount);
        $this->assertEquals(0.0, $globalSale->due_amount);

        // Verify no invalid rows in the batch
        $invalidRows = $batch->rows()->where('status', 'invalid')->get();
        $this->assertCount(0, $invalidRows, 'No rows should be marked invalid');

        // Verify all 4 rows were processed
        $processedRows = $batch->rows()->whereIn('status', ['processed', 'skipped'])->count();
        $this->assertEquals(4, $processedRows, 'All rows must be processed or skipped');
    }

    public function test_jl_2025_25893_shape_exact_one_cent_artifact_with_split_owner_imports_as_paid()
    {
        $batch = SalesImportBatch::create([
            'status' => SalesImportBatch::STATUS_QUEUED,
            'total_rows' => 2,
            'user_id' => $this->user->id, 'source_csv_path' => 'dummy.csv', 'file_sha256' => 'dummy',
        ]);

        $common = [
            'no_faktur' => 'JL.2025.25893',
            'tanggal' => '03/12/2025',
            'customer' => 'CASH',
            'diskon' => '13824.638592',
            'sisa_tagihan' => '0',
            'status_hari_ini' => 'Lunas',
            'pembayaran' => '200000.0',
            'source_total' => '200000.0',
        ];

        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => array_merge($common, [
                'produk' => '* ROBOT KEY + MOUSE KM3100 WIRELESS',
                'kuantitas' => '1.0',
                'harga_satuan' => '153153.153153',
                'pajak' => '15671.4928828672099998432850711713279',
                'tarif_pajak' => '11.0',
            ]),
        ]);

        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 2,
            'raw_json' => array_merge($common, [
                'produk' => 'KEYBOARD USB MTECH STK01',
                'kuantitas' => '1.0',
                'harga_satuan' => '45000.0',
                'pajak' => '0.0',
            ]),
        ]);

        $this->service->processBatch($batch);

        $sales = Sale::where('imported_sales_reference_number', 'JL.2025.25893')->get();
        $this->assertCount(2, $sales);

        $otherSale = $sales->where('setting_id', $this->otherSetting->id)->first();
        $globalSale = $sales->where('setting_id', $this->globalSetting->id)->first();

        $this->assertNotNull($otherSale, 'Asterisk product must create sale in otherSetting');
        $this->assertNotNull($globalSale, 'Unmarked products must create sale in globalSetting');

        // 1-cent artifact means total mathematically was 200000.01, but source total is 200000.0.
        // It must import exactly matching 200000.0
        $this->assertEquals(200000.0, round($otherSale->total_amount + $globalSale->total_amount, 2));

        // It must be marked paid since status was Lunas
        $this->assertEquals('PAID', $otherSale->payment_status);
        $this->assertEquals('PAID', $globalSale->payment_status);

        $this->assertEquals($otherSale->total_amount, $otherSale->paid_amount);
        $this->assertEquals($globalSale->total_amount, $globalSale->paid_amount);
        $this->assertEquals(0.0, $otherSale->due_amount);
        $this->assertEquals(0.0, $globalSale->due_amount);

        // Verify no invalid rows in the batch
        $invalidRows = $batch->rows()->where('status', 'invalid')->get();
        $this->assertCount(0, $invalidRows, 'No rows should be marked invalid');

        // Verify both rows were processed
        $processedRows = $batch->rows()->whereIn('status', ['processed', 'skipped'])->count();
        $this->assertEquals(2, $processedRows, 'All rows must be processed or skipped');
    }

    public function test_jl_2026_2146_shape_single_row_lunas_no_drift_failure()
    {
        $batch = SalesImportBatch::create([
            'status' => SalesImportBatch::STATUS_QUEUED,
            'total_rows' => 1,
            'user_id' => $this->user->id, 'source_csv_path' => 'dummy.csv', 'file_sha256' => 'dummy',
        ]);

        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => [
                'no_faktur' => 'JL.2026.2146',
                'tanggal' => '29/01/2026',
                'customer' => 'PT EPSON INDONESIA',
                'diskon' => '0.0',
                'sisa_tagihan' => '0',
                'status_hari_ini' => 'Lunas',
                'pembayaran' => '15584400.0',
                'source_total' => '15584400.0',
                'produk' => 'Support Rack Display',
                'kuantitas' => '1.0',
                'harga_satuan' => '14040000.0',
                'pajak' => '1544399.999999999999984556',
                'tarif_pajak' => '11.0',
            ],
        ]);

        $this->service->processBatch($batch);

        $sales = Sale::where('imported_sales_reference_number', 'JL.2026.2146')->get();
        $this->assertCount(1, $sales);

        $sale = $sales->first();

        $this->assertEquals(15584400.0, $sale->total_amount);
        $this->assertEquals('PAID', $sale->payment_status);
        $this->assertEquals(15584400.0, $sale->paid_amount);
        $this->assertEquals(0.0, $sale->due_amount);

        // Verify no invalid rows in the batch
        $invalidRows = $batch->rows()->where('status', 'invalid')->get();
        $this->assertCount(0, $invalidRows, 'No rows should be marked invalid');

        // Verify the single row was processed
        $processedRows = $batch->rows()->whereIn('status', ['processed', 'skipped'])->count();
        $this->assertEquals(1, $processedRows, 'Row must be processed or skipped');
    }

    public function test_same_no_faktur_non_contiguous_rows_reconcile_as_one_invoice()
    {
        $batch = SalesImportBatch::create([
            'status' => SalesImportBatch::STATUS_QUEUED,
            'total_rows' => 3,
            'user_id' => $this->user->id, 'source_csv_path' => 'dummy.csv', 'file_sha256' => 'dummy',
        ]);

        $common = [
            'tanggal' => '01/01/2026',
            'customer' => 'Customer A',
            'diskon' => '0.0',
            'sisa_tagihan' => '0',
            'status_hari_ini' => 'Lunas',
            'pembayaran' => '3000.0',
            'source_total' => '3000.0',
        ];

        // Row 1: Invoice A
        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => array_merge($common, [
                'no_faktur' => 'INV-NON-CONTIG',
                'produk' => 'Product 1',
                'kuantitas' => '1.0',
                'harga_satuan' => '1000.0',
                'pajak' => '0.0',
            ]),
        ]);

        // Row 2: Invoice B (Interfering row)
        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 2,
            'raw_json' => [
                'no_faktur' => 'INV-OTHER',
                'tanggal' => '01/01/2026',
                'customer' => 'Customer B',
                'produk' => 'Product X',
                'kuantitas' => '1.0',
                'harga_satuan' => '500.0',
                'pembayaran' => '500.0',
                'source_total' => '500.0',
                'sisa_tagihan' => '0',
                'status_hari_ini' => 'Lunas',
            ],
        ]);

        // Row 3: Invoice A (Continuation of Invoice A)
        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 3,
            'raw_json' => array_merge($common, [
                'no_faktur' => 'INV-NON-CONTIG',
                'produk' => 'Product 2',
                'kuantitas' => '2.0',
                'harga_satuan' => '1000.0',
                'pajak' => '0.0',
            ]),
        ]);

        $this->service->processBatch($batch);

        $sales = Sale::where('imported_sales_reference_number', 'INV-NON-CONTIG')->get();
        // Since there is no marker, both rows go to globalSetting.
        // Therefore, it should combine into a single sale.
        $this->assertCount(1, $sales);

        $sale = $sales->first();

        // The total amount should be 3000 (1*1000 + 2*1000)
        $this->assertEquals(3000.0, $sale->total_amount);
        $this->assertEquals('PAID', $sale->payment_status);
        $this->assertEquals(3000.0, $sale->paid_amount);

        // Ensure the interfering invoice also imported correctly
        $otherSale = Sale::where('imported_sales_reference_number', 'INV-OTHER')->first();
        $this->assertNotNull($otherSale);
        $this->assertEquals(500.0, $otherSale->total_amount);
    }
}
