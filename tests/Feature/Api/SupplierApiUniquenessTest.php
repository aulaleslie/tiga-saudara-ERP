<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;
use Modules\Setting\Entities\Setting as SettingEntity;

class SupplierApiUniquenessTest extends TestCase
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

    public function test_api_post_suppliers_rejects_duplicate_supplier_name(): void
    {
        $setting = $this->createSetting('API Uniqueness Test');

        // Create first supplier via API
        $this->withSession(['setting_id' => $setting->id])
            ->post('/api/suppliers', [
                'supplier_name' => 'PT MAJU',
                'contact_name' => 'Contact One',
                'phone' => '081230000001',
                'email' => 'contact1@example.com',
            ])
            ->assertStatus(200);

        // Attempt to create duplicate via API
        $response = $this->withSession(['setting_id' => $setting->id])
            ->post('/api/suppliers', [
                'supplier_name' => 'PT MAJU',
                'contact_name' => 'Contact Two',
                'phone' => '081230000002',
                'email' => 'contact2@example.com',
            ]);

        // Validation should have prevented duplicate creation
        $this->assertCount(1, Supplier::where('supplier_name', 'PT MAJU')->get());

        // Check that validation error was returned (status 422 expected)
        $this->assertEquals(422, $response->status(), 'Expected validation error response with status 422, got ' . $response->status());
        $response->assertJsonValidationErrors(['supplier_name']);
    }

    public function test_api_post_suppliers_accepts_distinct_name(): void
    {
        $setting = $this->createSetting('API Distinct Test');

        $response = $this->withSession(['setting_id' => $setting->id])
            ->post('/api/suppliers', [
                'supplier_name' => 'PT MAJU',
                'contact_name' => 'Contact One',
                'phone' => '081230000001',
                'email' => 'contact1@example.com',
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'id',
            'supplier_name',
            'contact_name',
            'display_name',
            'payment_term_id',
        ]);
        $this->assertCount(1, Supplier::where('supplier_name', 'PT MAJU')->get());
    }
}
