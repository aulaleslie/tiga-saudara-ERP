<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sale\Entities\SalesImportBatch;
use Modules\Sale\Entities\SalesImportRow;
use Modules\Sale\Services\SalesImportService;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SalesImportDateErrorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        $currency = \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

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
        
        \Modules\Setting\Entities\Location::create([
            'setting_id' => $this->setting->id,
            'name' => 'Test Location',
        ]);
    }

    /** @test */
    public function it_handles_whitespace_date_gracefully()
    {
        // Case 1: `tanggal` is valid, `tanggal_jatuh_tempo` is whitespace " "
        // THIS SHOULD NOW SUCCEED (Default to Sale Date)
        $rowData = [
            'tanggal' => '23/02/2021',
            'no_faktur' => 'JL.TEST.001',
            'customer' => 'CASH',
            'produk' => 'COMPUTER',
            'kuantitas' => '1.0',
            'satuan' => 'PCS',
            'harga_satuan' => '60000.0', 
            'pajak' => '0.0', 
            'tanggal_jatuh_tempo' => ' ', // Whitespace!
            'sisa_tagihan' => '0.0',
            'tag' => 'cv tiga nusa', // Valid tag mapping
        ];

        // Should NOT throw exception and row should be processed
        $this->runBatchWithRow($rowData, 'Whitespace Date', true); 
    }

    /** @test */
    public function it_fails_on_short_date()
    {
        // Case 2: `tanggal` is invalid
        // THIS SHOULD STILL FAIL (Row Invalid)
        $rowData = [
            'tanggal' => 'invalid-date', // Definitively invalid
            'no_faktur' => 'JL.TEST.002',
            'customer' => 'CASH',
            'produk' => 'COMPUTER',
            'kuantitas' => '1.0',
            'satuan' => 'PCS',
            'harga_satuan' => '60000.0', 
            'pajak' => '0.0', 
            'tanggal_jatuh_tempo' => '23/02/2021',
            // Other fields to match the row
            'sisa_tagihan' => '0.0',
            'tag' => 'cv tiga nusa', // Valid tag mapping
        ];

        $this->runBatchWithRow($rowData, 'Short Date', false);
    }

    protected function runBatchWithRow(array $rowData, string $scenario, bool $shouldSucceed)
    {
        $user = \App\Models\User::factory()->create(['is_active' => 1]);

        $batch = SalesImportBatch::create([
            'user_id' => $user->id,
            'source_csv_path' => 'dummy.csv',
            'file_name' => 'dummy.csv',
            'file_sha256' => 'dummy',
            'status' => SalesImportBatch::STATUS_PROCESSING,
        ]);

        $row = SalesImportRow::create([
            'batch_id' => $batch->id,
            'row_number' => 1,
            'raw_json' => $rowData,
            'status' => 'pending', // Use lowercase string explicitly
        ]);
        
        $service = app(SalesImportService::class);
        
        try {
            $service->processBatch($batch);
        } catch (\Exception $e) {
             // If we expect success, this is a fail
             if ($shouldSucceed) {
                 $this->fail("Unexpected exception for {$scenario}: " . $e->getMessage());
             }
             // If we expect fail, catching exception depends on logic. 
             // Service usually catches and marks invalid.
        }

        $row->refresh();
        
        if ($shouldSucceed) {
            // Expect Processed (or at least NOT Invalid)
            $this->assertEquals(SalesImportRow::STATUS_PROCESSED, $row->status, "Row failed for {$scenario}. Error: " . $row->error_message);
        } else {
            // Expect Invalid
            $this->assertEquals(SalesImportRow::STATUS_INVALID, $row->status, "Row should be invalid for {$scenario}. Status: " . $row->status);
            $this->assertStringContainsString('Not enough data', $row->error_message, "Error message mismatch for {$scenario}");
        }
    }
}
