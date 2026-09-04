<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Services\PurchaseImportService;
use Modules\Expense\Services\ExpenseImportService;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

class SupplierImportGlobalMatchingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $currency = Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $this->currency = $currency;
    }

    protected Currency $currency;

    private function createSetting(string $name): Setting
    {
        return Setting::factory()->create([
            'company_name' => $name,
            'company_email' => strtolower(str_replace(' ', '.', $name)) . '@example.com',
            'company_address' => 'Test Address',
            'company_phone' => '081200000000',
        ]);
    }

    public function test_purchase_import_matches_existing_supplier_from_different_setting(): void
    {
        $setting1 = $this->createSetting('Setting 1');
        $setting2 = $this->createSetting('Setting 2');

        // Create supplier in setting 1
        $supplier = Supplier::create([
            'supplier_name' => 'PT MAJU',
            'contact_name' => 'Contact One',
            'supplier_email' => 'contact1@example.com',
            'supplier_phone' => '081230000001',
            'address' => '',
            'city' => '',
            'country' => '',
            'setting_id' => $setting1->id,
        ]);

        $service = app(PurchaseImportService::class);

        // Import the same supplier from setting 2 should match the existing global record
        $importedSupplier = $service->findOrCreateSupplier('PT MAJU', $setting2->id);

        // Should match the existing supplier from setting 1, not create a new one
        $this->assertEquals($supplier->id, $importedSupplier->id);
        $this->assertCount(1, Supplier::where('supplier_name', 'PT MAJU')->get());
    }

    public function test_expense_import_matches_existing_supplier_from_different_setting(): void
    {
        $setting1 = $this->createSetting('Setting 1');
        $setting2 = $this->createSetting('Setting 2');

        // Create supplier in setting 1
        $supplier = Supplier::create([
            'supplier_name' => 'PT MAJU',
            'contact_name' => 'Contact One',
            'supplier_email' => 'contact1@example.com',
            'supplier_phone' => '081230000001',
            'address' => '',
            'city' => '',
            'country' => '',
            'setting_id' => $setting1->id,
        ]);

        $service = app(ExpenseImportService::class);

        // Import the same supplier from setting 2 should match the existing global record
        $importedSupplier = $service->findOrCreateSupplier('PT MAJU', $setting2->id);

        // Should match the existing supplier from setting 1, not create a new one
        $this->assertEquals($supplier->id, $importedSupplier->id);
        $this->assertCount(1, Supplier::where('supplier_name', 'PT MAJU')->get());
    }

    public function test_expense_import_stores_null_contact_name_placeholder(): void
    {
        $setting = $this->createSetting('Placeholder Test');

        $service = app(ExpenseImportService::class);

        // Import supplier without contact name
        $supplier = $service->findOrCreateSupplier('PT MAJU', $setting->id);

        // Should store null, not 'Imported Supplier'
        $this->assertNull($supplier->contact_name);
    }

    public function test_multiple_imports_without_contact_names_do_not_collide(): void
    {
        $setting1 = $this->createSetting('Setting 1');
        $setting2 = $this->createSetting('Setting 2');

        $service1 = app(ExpenseImportService::class);
        $service2 = app(ExpenseImportService::class);

        // First import without contact name
        $supplier1 = $service1->findOrCreateSupplier('PT MAJU', $setting1->id);

        // Second import of a different supplier without contact name
        $supplier2 = $service2->findOrCreateSupplier('PT SEJAHTERA', $setting2->id);

        // Both should have null contact_name and neither should collide
        $this->assertNull($supplier1->contact_name);
        $this->assertNull($supplier2->contact_name);
        $this->assertNotEquals($supplier1->id, $supplier2->id);
        $this->assertCount(2, Supplier::all());
    }

    public function test_purchase_import_creates_new_supplier_when_no_match(): void
    {
        $setting = $this->createSetting('Create New Test');

        $service = app(PurchaseImportService::class);

        // Import a new supplier that doesn't exist
        $supplier = $service->findOrCreateSupplier('PT NEW SUPPLIER', $setting->id);

        $this->assertNotNull($supplier->id);
        $this->assertEquals('PT NEW SUPPLIER', $supplier->supplier_name);
        $this->assertEquals($setting->id, $supplier->setting_id);
        $this->assertCount(1, Supplier::where('supplier_name', 'PT NEW SUPPLIER')->get());
    }
}
