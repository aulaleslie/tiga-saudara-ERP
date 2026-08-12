<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Sale\Entities\Sale;
use Modules\Sale\Entities\SalesImportBatch;
use Modules\Sale\Entities\SalesImportRow;
use Modules\Sale\Services\SalesImportService;
use Modules\Setting\Entities\ChartOfAccount;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SalesImportArchivedDuplicateHandlingTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Setting $setting2;
    private Customer $customer;
    private SalesImportService $importService;
    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        // Create PERDANA setting (required for default tenant resolution in import)
        $this->setting = Setting::create([
            'company_name' => 'PERDANA',
            'company_email' => 'test@company.com',
            'company_phone' => '123456',
            'notification_email' => 'notify@company.com',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address',
        ]);

        // Create second setting with different name
        $this->setting2 = Setting::create([
            'company_name' => 'CV TIGA NUSA COMPUTER',
            'company_email' => 'test2@company.com',
            'company_phone' => '654321',
            'notification_email' => 'notify2@company.com',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address 2',
        ]);

        // Create locations for both settings
        \Modules\Setting\Entities\Location::create([
            'name' => 'Test Location',
            'setting_id' => $this->setting->id,
        ]);

        \Modules\Setting\Entities\Location::create([
            'name' => 'Test Location 2',
            'setting_id' => $this->setting2->id,
        ]);

        $this->customer = Customer::create([
            'customer_name' => 'Test Customer',
            'customer_phone' => '123',
            'customer_email' => 'customer@test.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id,
        ]);

        $cashCoa = ChartOfAccount::create([
            'account_number' => '1101',
            'name' => 'Cash on Hand',
            'category' => 'Kas & Bank',
            'setting_id' => $this->setting->id,
        ]);

        PaymentMethod::create([
            'name' => 'CASH',
            'coa_id' => $cashCoa->id,
            'is_cash' => true,
            'requires_reference' => false,
        ]);

        $this->importService = app(SalesImportService::class);
    }

    private function baseRow(array $overrides = []): array
    {
        return array_merge([
            'tanggal' => '01/10/2024',
            'no_faktur' => 'INV-001',
            'customer' => 'TEST CUSTOMER',
            'produk' => 'TEST PRODUCT',
            'kuantitas' => '1',
            'satuan' => 'PCS',
            'harga_satuan' => '100000',
            'tarif_pajak' => '0',
            'pajak' => '0',
            'tag' => '',  // Empty tag defaults to PERDANA
        ], $overrides);
    }

    private function createImportBatch(array $rows): SalesImportBatch
    {
        $user = \App\Models\User::factory()->create(['is_active' => 1]);
        $batch = SalesImportBatch::create([
            'user_id' => $user->id,
            'source_csv_path' => 'dummy.csv',
            'file_sha256' => 'dummy-' . uniqid(),
            'status' => SalesImportBatch::STATUS_PROCESSING,
        ]);

        foreach ($rows as $index => $rowData) {
            SalesImportRow::create([
                'batch_id' => $batch->id,
                'row_number' => $index + 2,
                'raw_json' => $rowData,
            ]);
        }

        return $batch;
    }

    public function test_import_skips_active_same_setting_external_number(): void
    {
        // Create an active sale with an external customer reference number
        $activeSale = Sale::create([
            'date' => now(),
            'reference' => 'SAL-001',
            'imported_sales_reference_number' => 'CUST-EXT-12345',
            'setting_id' => $this->setting->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'archived_at' => null,
        ]);

        $batch = $this->createImportBatch([
            $this->baseRow(['no_faktur' => 'CUST-EXT-12345']),
        ]);

        $this->importService->processBatch($batch);

        $row = SalesImportRow::where('batch_id', $batch->id)->first();
        $this->assertEquals(SalesImportRow::STATUS_SKIPPED, $row->status);
        $this->assertEquals($activeSale->id, $row->sale_id);

        // Verify no new duplicate sale was created
        $saleCount = Sale::where('imported_sales_reference_number', 'CUST-EXT-12345')
            ->where('setting_id', $this->setting->id)
            ->count();
        $this->assertEquals(1, $saleCount);
    }

    public function test_import_allows_archived_same_setting_external_number(): void
    {
        // Create an archived sale with an external customer reference number
        $archivedSale = Sale::create([
            'date' => now(),
            'reference' => 'SAL-001',
            'imported_sales_reference_number' => 'CUST-EXT-ARCHIVED-12345',
            'setting_id' => $this->setting->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'archived_at' => now(),
        ]);

        $batch = $this->createImportBatch([
            $this->baseRow(['no_faktur' => 'CUST-EXT-ARCHIVED-12345']),
        ]);

        $this->importService->processBatch($batch);

        $row = SalesImportRow::where('batch_id', $batch->id)->first();
        // Row should not be marked as skipped since archived records don't block imports
        $this->assertNotEquals(SalesImportRow::STATUS_SKIPPED, $row->status);

        // A new active sale should have been created
        $newSale = Sale::where('imported_sales_reference_number', 'CUST-EXT-ARCHIVED-12345')
            ->where('setting_id', $this->setting->id)
            ->whereNull('archived_at')
            ->first();
        $this->assertNotNull($newSale);
        $this->assertNotEquals($archivedSale->id, $newSale->id);
    }

    public function test_import_allows_same_number_different_setting(): void
    {
        // Create an active sale with an external reference number in setting2
        $saleInOtherSetting = Sale::create([
            'date' => now(),
            'reference' => 'SAL-002',
            'imported_sales_reference_number' => 'CUST-EXT-SAME-12345',
            'setting_id' => $this->setting2->id,
            'customer_name' => $this->customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Sale::STATUS_DISPATCHED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'archived_at' => null,
        ]);

        $batch = $this->createImportBatch([
            $this->baseRow(['no_faktur' => 'CUST-EXT-SAME-12345']),
        ]);

        $this->importService->processBatch($batch);

        $row = SalesImportRow::where('batch_id', $batch->id)->first();
        // Row should not be skipped because the number exists in a different setting
        $this->assertNotEquals(SalesImportRow::STATUS_SKIPPED, $row->status);

        // A new sale should have been created in the current setting
        $newSale = Sale::where('imported_sales_reference_number', 'CUST-EXT-SAME-12345')
            ->where('setting_id', $this->setting->id)
            ->whereNull('archived_at')
            ->first();
        $this->assertNotNull($newSale);
    }
}
