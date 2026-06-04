<?php

namespace Modules\Purchase\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Purchase\Entities\PurchaseImportBatch;
use Modules\Purchase\Entities\PurchaseImportRow;
use Modules\Purchase\Services\PurchaseImportService;
use Tests\TestCase;

class PurchaseImportPrecisionDriftTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

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

        \Modules\Setting\Entities\Setting::create([
            'company_name' => 'PERDANA',
            'company_email' => 'perdana@test.com',
            'company_phone' => '123',
            'company_address' => 'Test',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
        ]);
    }

    public function test_it_rejects_purchase_import_when_source_drift_exceeds_default_tolerance()
    {
        $batch = PurchaseImportBatch::create([
            'source_csv_path' => 'test_purchase.csv',
            'status' => 'pending',
            'total_rows' => 1,
            'user_id' => $this->user->id,
            'file_sha256' => 'dummy_hash',
        ]);

        PurchaseImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'status' => 'pending',
            'raw_json' => [
                'tanggal' => '01/01/2021',
                'no_faktur' => 'PUR-001',
                'supplier' => 'Vendor A',
                'produk' => 'Product A',
                'kuantitas' => '1',
                'satuan' => 'PCS',
                'harga_satuan' => '100000.00', // recomputed 100,000
                'pajak' => '0',
                'sisa_tagihan_hari_ini' => '0',
                'pembayaran' => '100002.00',
                'source_total' => '100002.00', // drift of 2 is > 1.00 but within 5.00 sales tolerance
                'status_hari_ini' => 'Lunas',
            ],
        ]);

        $coa = \Modules\Setting\Entities\ChartOfAccount::create([
            'name' => 'Cash Account',
            'account_number' => '1000',
            'category' => 'Kas & Bank',
            'setting_id' => 1,
        ]);

        // We need a payment method for the cash payment to be created
        \Modules\Setting\Entities\PaymentMethod::create([
            'name' => 'CASH',
            'is_cash' => true,
            'coa_id' => $coa->id,
        ]);

        $service = app(PurchaseImportService::class);
        $service->processBatch($batch);

        $row = PurchaseImportRow::first();
        $this->assertEquals('processed', $row->status);
        
        $purchase = \Modules\Purchase\Entities\Purchase::first();
        $this->assertNotNull($purchase);
        // Calculated document total is 100000. Settlement must not exceed it.
        $this->assertEquals(100000.00, $purchase->total_amount);
        $this->assertEquals(100000.00, $purchase->paid_amount);
        $this->assertEquals(0, $purchase->due_amount);
    }
}
