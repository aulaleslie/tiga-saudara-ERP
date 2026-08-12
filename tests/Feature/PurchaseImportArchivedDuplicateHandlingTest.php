<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Entities\Purchase;
use Modules\Purchase\Entities\PurchaseImportBatch;
use Modules\Purchase\Entities\PurchaseImportRow;
use Modules\Purchase\Services\PurchaseImportService;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class PurchaseImportArchivedDuplicateHandlingTest extends TestCase
{
    use RefreshDatabase;

    private Setting $setting;
    private Setting $setting2;
    private Supplier $supplier;
    private Location $location;
    private PurchaseImportService $importService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create PERDANA setting (required for default tenant resolution in import)
        $this->setting = Setting::create([
            'company_name' => 'PERDANA',
            'company_email' => 'test@company.com',
            'company_phone' => '123456',
            'notification_email' => 'notify@company.com',
            'default_currency_id' => 1,
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
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Footer',
            'company_address' => 'Address 2',
        ]);

        // Create a location for the second setting (needed for purchases)
        $location2 = \Modules\Setting\Entities\Location::create([
            'name' => 'Test Location 2',
            'setting_id' => $this->setting2->id,
        ]);

        $this->supplier = Supplier::create([
            'supplier_name' => 'Test Supplier',
            'supplier_phone' => '123',
            'supplier_email' => 'supplier@test.com',
            'city' => 'City',
            'country' => 'Country',
            'address' => 'Address',
            'setting_id' => $this->setting->id,
        ]);

        $this->location = Location::create([
            'name' => 'Test Location',
            'setting_id' => $this->setting->id,
        ]);

        // Create payment method for PERDANA
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

        $this->importService = app(PurchaseImportService::class);
    }

    private function baseRow(array $overrides = []): array
    {
        return array_merge([
            'tanggal' => '01/10/2024',
            'no_faktur' => 'INV-001',
            'supplier' => 'Test Supplier',
            'produk' => 'TEST PRODUCT',
            'kuantitas' => '1',
            'satuan' => 'PCS',
            'harga_satuan' => '100000',
            'tarif_pajak' => '0',
            'pajak' => '0',
            'tag' => '',  // Empty tag defaults to PERDANA
        ], $overrides);
    }

    private function createImportBatch(array $rows): PurchaseImportBatch
    {
        $user = \App\Models\User::factory()->create(['is_active' => 1]);
        $batch = PurchaseImportBatch::create([
            'user_id' => $user->id,
            'source_csv_path' => 'dummy.csv',
            'file_sha256' => 'dummy-' . uniqid(),
            'status' => PurchaseImportBatch::STATUS_PROCESSING,
            'setting_id' => $this->setting->id,
        ]);

        foreach ($rows as $index => $rowData) {
            PurchaseImportRow::create([
                'batch_id' => $batch->id,
                'row_number' => $index + 2,
                'raw_json' => $rowData,
            ]);
        }

        return $batch;
    }

    public function test_import_skips_active_same_setting_external_number(): void
    {
        // Create an active purchase with an external supplier number
        $activePurchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'supplier_purchase_number' => 'EXT-12345',
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'Approved',
            'archived_at' => null,
        ]);

        $batch = $this->createImportBatch([
            $this->baseRow(['no_faktur' => 'EXT-12345']),
        ]);

        $this->importService->processBatch($batch);

        $row = PurchaseImportRow::where('batch_id', $batch->id)->first();
        $this->assertEquals(PurchaseImportRow::STATUS_SKIPPED, $row->status);
        $this->assertEquals($activePurchase->id, $row->purchase_id);

        // Verify no new duplicate purchase was created
        $purchaseCount = Purchase::where('supplier_purchase_number', 'EXT-12345')
            ->where('setting_id', $this->setting->id)
            ->count();
        $this->assertEquals(1, $purchaseCount);
    }

    public function test_import_allows_archived_same_setting_external_number(): void
    {
        // Create an archived purchase with an external supplier number
        $archivedPurchase = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-001',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'supplier_purchase_number' => 'EXT-ARCHIVED-12345',
            'setting_id' => $this->setting->id,
            'location_id' => $this->location->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'Approved',
            'archived_at' => now(),
        ]);

        $batch = $this->createImportBatch([
            $this->baseRow(['no_faktur' => 'EXT-ARCHIVED-12345']),
        ]);

        $this->importService->processBatch($batch);

        $row = PurchaseImportRow::where('batch_id', $batch->id)->first();
        // Row should not be marked as skipped since archived records don't block imports
        $this->assertNotEquals(PurchaseImportRow::STATUS_SKIPPED, $row->status);

        // A new active purchase should have been created
        $newPurchase = Purchase::where('supplier_purchase_number', 'EXT-ARCHIVED-12345')
            ->where('setting_id', $this->setting->id)
            ->whereNull('archived_at')
            ->first();
        $this->assertNotNull($newPurchase);
        $this->assertNotEquals($archivedPurchase->id, $newPurchase->id);
    }

    public function test_import_allows_same_number_different_setting(): void
    {
        // Create an active purchase with an external supplier number in setting2
        $purchaseInOtherSetting = Purchase::create([
            'date' => now(),
            'due_date' => now()->addDays(30),
            'reference' => 'PO-002',
            'supplier_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->supplier_name,
            'supplier_purchase_number' => 'EXT-SAME-12345',
            'setting_id' => $this->setting2->id,
            'location_id' => $this->location->id,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'due_amount' => 1000,
            'status' => Purchase::STATUS_APPROVED,
            'payment_status' => 'Unpaid',
            'payment_method' => 'Cash',
            'approval_status' => 'Approved',
            'archived_at' => null,
        ]);

        $batch = $this->createImportBatch([
            $this->baseRow(['no_faktur' => 'EXT-SAME-12345']),
        ]);

        $this->importService->processBatch($batch);

        $row = PurchaseImportRow::where('batch_id', $batch->id)->first();
        // Row should not be skipped because the number exists in a different setting
        $this->assertNotEquals(PurchaseImportRow::STATUS_SKIPPED, $row->status);

        // A new purchase should have been created in the current setting
        $newPurchase = Purchase::where('supplier_purchase_number', 'EXT-SAME-12345')
            ->where('setting_id', $this->setting->id)
            ->whereNull('archived_at')
            ->first();
        $this->assertNotNull($newPurchase);
    }
}
