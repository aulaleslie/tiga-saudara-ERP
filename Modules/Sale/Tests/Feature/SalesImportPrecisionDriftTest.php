<?php

namespace Modules\Sale\Tests\Feature;

use App\Support\ImportPaymentSummaryResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sale\Entities\SalesImportBatch;
use Modules\Sale\Entities\SalesImportRow;
use Modules\Sale\Services\SalesImportService;
use Tests\TestCase;

class SalesImportPrecisionDriftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = \App\Models\User::factory()->create();
        
        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name'      => 'Rupiah',
            'code'               => 'IDR',
            'symbol'             => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator'  => ',',
        ]);

        $setting = \Modules\Setting\Entities\Setting::create([
            'company_name' => 'PERDANA',
            'company_email' => 'perdana@test.com',
            'company_phone' => '123',
            'company_address' => 'Test',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
        ]);

        $location = \Modules\Setting\Entities\Location::create([
            'setting_id' => $setting->id,
            'name' => 'Main Location',
        ]);

        $coa = \Modules\Setting\Entities\ChartOfAccount::create([
            'setting_id' => \Modules\Setting\Entities\Setting::first()->id,
            'name' => 'Kas',
            'account_number' => '1001',
            'category' => 'Kas & Bank',
        ]);

        \Modules\Setting\Entities\PaymentMethod::create([
            'name' => 'CASH',
            'coa_id' => $coa->id,
            'is_cash' => true,
        ]);
    }

    public function test_it_accepts_sales_precision_drift_when_settlement_reconciles_to_source_total()
    {
        // 126,964,597.07 recomputed, source total 126,964,600.00 (drift 2.93)
        $batch = SalesImportBatch::create([
            'source_csv_path' => 'test.csv',
            'status' => 'pending',
            'user_id' => $this->user->id,
            'file_sha256' => 'dummy_hash',
            'total_rows' => 2,
        ]);

        $row1 = SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'status' => 'pending',
            'raw_json' => [
                'tanggal' => '01/01/2021',
                'no_faktur' => 'TN.20211796',
                'customer' => 'Cust A',
                'produk' => 'Product A',
                'kuantitas' => '1',
                'satuan' => 'PCS',
                // line subtotal = 100,000,000
                'harga_satuan' => '100000000',
                'pajak' => '11000000', // 11,000,000
                'sisa_tagihan_hari_ini' => '0',
                'pembayaran' => '126964600.00',
                'source_total' => '126964600.00',
                'status_hari_ini' => 'Lunas',
            ],
        ]);

        $row2 = SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 2,
            'status' => 'pending',
            'raw_json' => [
                'tanggal' => '01/01/2021',
                'no_faktur' => 'TN.20211796',
                'customer' => 'Cust A',
                'produk' => 'Product B',
                'kuantitas' => '1',
                'satuan' => 'PCS',
                // line subtotal = 14,382,519.88
                'harga_satuan' => '14382519.88',
                'pajak' => '1582077.19', // total subtotal = 15,964,597.07
                'sisa_tagihan_hari_ini' => '0',
                'pembayaran' => '126964600.00',
                'source_total' => '126964600.00',
                'status_hari_ini' => 'Lunas',
            ],
        ]);

        // Recomputed = 100,000,000 + 11,000,000 + 14,382,519.88 + 1,582,077.19 = 126,964,597.07
        // Drift = 2.93

        $service = app(SalesImportService::class);
        $service->processBatch($batch);

        $this->assertEquals(strtolower(SalesImportBatch::STATUS_COMPLETED), strtolower($batch->fresh()->status));
        
        $this->assertEquals('processed', strtolower($row1->fresh()->status));
        $this->assertEquals('processed', strtolower($row2->fresh()->status));

        $sale = \Modules\Sale\Entities\Sale::first();
        $this->assertNotNull($sale);
        
        // Assert the accepted sale uses source Total for total_amount
        $this->assertEquals(126964600.00, $sale->total_amount);
        $this->assertEquals(126964600.00, $sale->paid_amount);
        $this->assertEquals(0, $sale->due_amount);

        // creates active payment rows reconciled to source Total
        $payment = \Modules\Sale\Entities\SalePayment::where('sale_id', $sale->id)->first();
        $this->assertNotNull($payment);
        $this->assertEquals(126964600.00, $payment->amount);
    }

    public function test_it_rejects_sales_precision_drift_when_exceeding_absolute_limit()
    {
        $limit = ImportPaymentSummaryResolver::SALES_PRECISION_DRIFT_ABSOLUTE;
        $batch = SalesImportBatch::create([
            'source_csv_path' => 'test2.csv',
            'status' => 'pending',
            'user_id' => $this->user->id,
            'file_sha256' => 'dummy_hash',
            'total_rows' => 1,
        ]);

        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'status' => 'pending',
            'raw_json' => [
                'tanggal' => '01/01/2021',
                'no_faktur' => 'INV-EXCEED',
                'customer' => 'Cust A',
                'produk' => 'Product A',
                'kuantitas' => '1',
                'satuan' => 'PCS',
                'harga_satuan' => '100000.00', // recomputed 100k
                'pajak' => '0',
                'sisa_tagihan_hari_ini' => '0',
                'pembayaran' => (string)(100000.00 + $limit + 0.01),
                'source_total' => (string)(100000.00 + $limit + 0.01),
                'status_hari_ini' => 'Lunas',
            ],
        ]);

        $service = app(SalesImportService::class);
        $service->processBatch($batch);

        $row = SalesImportRow::first();
        $this->assertEquals('invalid', $row->status);
        $this->assertStringContainsString('source Total does not reconcile with calculated document total.', $row->error_message ?? '');
    }

    public function test_it_rejects_sales_precision_drift_when_settlement_does_not_reconcile_to_source_total()
    {
        $batch = SalesImportBatch::create([
            'source_csv_path' => 'test3.csv',
            'status' => 'pending',
            'user_id' => $this->user->id,
            'file_sha256' => 'dummy_hash',
            'total_rows' => 1,
        ]);

        SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'status' => 'pending',
            'raw_json' => [
                'tanggal' => '01/01/2021',
                'no_faktur' => 'INV-MISMATCH',
                'customer' => 'Cust A',
                'produk' => 'Product A',
                'kuantitas' => '1',
                'satuan' => 'PCS',
                'harga_satuan' => '1000000.00', 
                'pajak' => '0', // recomputed 1,000,000
                'sisa_tagihan_hari_ini' => '0',
                // Source total 1,000,002 (drift 2 is within 5 limit)
                // But pembayaran is 1,000,000 instead of 1,000,002
                'pembayaran' => '1000000.00',
                'source_total' => '1000002.00',
                'status_hari_ini' => 'Lunas',
            ],
        ]);

        $service = app(SalesImportService::class);
        $service->processBatch($batch);

        $row = SalesImportRow::first();
        $this->assertEquals('invalid', $row->status);
        $this->assertStringContainsString('source Total does not reconcile with calculated document total.', $row->error_message ?? '');
    }
}
