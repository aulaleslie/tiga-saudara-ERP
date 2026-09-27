<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Purchase\Services\PurchaseImportService;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Tests\TestCase;

class PurchaseImportInactiveMasterDataRejectionTest extends TestCase
{
    use RefreshDatabase;

    private Setting $settingA;
    private Setting $settingB;
    private PurchaseImportService $service;

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

        $this->settingA = Setting::create([
            'company_name' => 'Business A',
            'company_email' => 'a@example.com',
            'company_phone' => '111',
            'company_address' => 'Address A',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'a@example.com',
            'footer_text' => '',
        ]);

        $this->settingB = Setting::create([
            'company_name' => 'Business B',
            'company_email' => 'b@example.com',
            'company_phone' => '222',
            'company_address' => 'Address B',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'b@example.com',
            'footer_text' => '',
        ]);

        $this->service = new PurchaseImportService();
    }

    public function test_find_or_create_tax_rejects_inactive_matching_tax_instead_of_reactivating(): void
    {
        $tax = Tax::create(['name' => 'PPN 11%', 'value' => 11, 'is_active' => false]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('dinonaktifkan');

        try {
            $this->service->findOrCreateTax(11);
        } finally {
            $this->assertFalse($tax->fresh()->is_active, 'Import must not reactivate an inactive tax as a side effect.');
        }
    }

    public function test_find_or_create_supplier_rejects_inactive_matching_supplier_instead_of_reactivating(): void
    {
        $supplier = Supplier::create([
            'supplier_name' => 'Acme Supplier',
            'supplier_email' => 'acme@example.com',
            'supplier_phone' => '0800000000',
            'address' => 'Some Address',
            'city' => 'City',
            'country' => 'Indonesia',
            'setting_id' => $this->settingA->id,
            'is_active' => false,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('dinonaktifkan');

        try {
            $this->service->findOrCreateSupplier('Acme Supplier', $this->settingA->id);
        } finally {
            $this->assertFalse($supplier->fresh()->is_active, 'Import must not reactivate an inactive supplier as a side effect.');
        }
    }

    public function test_find_or_create_supplier_is_scoped_to_the_current_tenant(): void
    {
        // An inactive supplier with the same name exists in another business (Setting B).
        // Importing under Setting A must not match, reuse, or reactivate it — a new,
        // active supplier scoped to Setting A must be created instead.
        $otherTenantSupplier = Supplier::create([
            'supplier_name' => 'Shared Name Supplier',
            'supplier_email' => 'other@example.com',
            'supplier_phone' => '0800000001',
            'address' => 'Other Address',
            'city' => 'Other City',
            'country' => 'Indonesia',
            'setting_id' => $this->settingB->id,
            'is_active' => false,
        ]);

        $created = $this->service->findOrCreateSupplier('Shared Name Supplier', $this->settingA->id);

        $this->assertNotEquals($otherTenantSupplier->id, $created->id);
        $this->assertEquals($this->settingA->id, $created->setting_id);
        $this->assertNotFalse($created->is_active);
        $this->assertFalse($otherTenantSupplier->fresh()->is_active);
    }

    public function test_find_or_create_supplier_reuses_active_supplier_within_same_tenant(): void
    {
        $existing = Supplier::create([
            'supplier_name' => 'Repeat Supplier',
            'supplier_email' => 'repeat@example.com',
            'supplier_phone' => '0800000002',
            'address' => 'Address',
            'city' => 'City',
            'country' => 'Indonesia',
            'setting_id' => $this->settingA->id,
            'is_active' => true,
        ]);

        $resolved = $this->service->findOrCreateSupplier('Repeat Supplier', $this->settingA->id);

        $this->assertEquals($existing->id, $resolved->id);
    }
}
