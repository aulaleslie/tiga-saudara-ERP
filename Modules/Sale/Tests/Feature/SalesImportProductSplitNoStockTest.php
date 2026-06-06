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

    public function test_non_pkp_owners_persist_zero_tax_regardless_of_csv()
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
        $this->assertEquals(10000, $sale->total_amount); // 10 * 1000 + 0 tax
        $this->assertEquals(0, $sale->tax_amount);
        $this->assertEquals(0, $sale->tax_percentage);

        $detail = $sale->saleDetails->first();
        $this->assertEquals(0, $detail->product_tax_amount);
        $this->assertNull($detail->tax_id);
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
}
