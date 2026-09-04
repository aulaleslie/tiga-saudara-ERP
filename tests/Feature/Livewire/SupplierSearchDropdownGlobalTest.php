<?php

namespace Tests\Feature\Livewire;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;
use Modules\Setting\Entities\Setting as SettingEntity;

class SupplierSearchDropdownGlobalTest extends TestCase
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

    public function test_supplier_search_returns_suppliers_regardless_of_setting(): void
    {
        $setting1 = $this->createSetting('Setting 1');
        $setting2 = $this->createSetting('Setting 2');

        // Create suppliers in different settings
        Supplier::create([
            'supplier_name' => 'PT MAJU',
            'contact_name' => 'Contact One',
            'supplier_email' => 'contact1@example.com',
            'supplier_phone' => '081230000001',
            'address' => '',
            'city' => '',
            'country' => '',
            'setting_id' => $setting1->id,
            'is_active' => true,
        ]);

        Supplier::create([
            'supplier_name' => 'PT SEJAHTERA',
            'contact_name' => 'Contact Two',
            'supplier_email' => 'contact2@example.com',
            'supplier_phone' => '081230000002',
            'address' => '',
            'city' => '',
            'country' => '',
            'setting_id' => $setting2->id,
            'is_active' => true,
        ]);

        $this->withSession(['setting_id' => $setting1->id]);

        // Fetch suppliers from setting 1 (should include suppliers from both settings)
        $suppliers = Supplier::query()
            ->active()
            ->orderBy('supplier_name')
            ->get();

        $this->assertCount(2, $suppliers);
        $this->assertTrue($suppliers->pluck('supplier_name')->contains('PT MAJU'));
        $this->assertTrue($suppliers->pluck('supplier_name')->contains('PT SEJAHTERA'));
    }

    public function test_supplier_search_resolves_supplier_from_another_setting(): void
    {
        $setting1 = $this->createSetting('Setting 1');
        $setting2 = $this->createSetting('Setting 2');

        // Create supplier in setting 2
        $supplier = Supplier::create([
            'supplier_name' => 'PT MAJU',
            'contact_name' => 'Contact One',
            'supplier_email' => 'contact1@example.com',
            'supplier_phone' => '081230000001',
            'address' => '',
            'city' => '',
            'country' => '',
            'setting_id' => $setting2->id,
            'is_active' => true,
        ]);

        $this->withSession(['setting_id' => $setting1->id]);

        // Resolve supplier by ID from setting 1 should find supplier from setting 2
        $resolved = Supplier::query()->find($supplier->id);

        $this->assertNotNull($resolved);
        $this->assertEquals($supplier->id, $resolved->id);
        $this->assertEquals('PT MAJU', $resolved->supplier_name);
    }
}
