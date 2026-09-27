<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Supplier;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SupplierNameUniquenessTest extends TestCase
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

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['suppliers.create', 'suppliers.edit', 'suppliers.access'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    protected Currency $currency;

    public function test_store_rejects_duplicate_supplier_name(): void
    {
        $setting = $this->createSetting('Supplier Name Uniqueness Test');
        $user = $this->createUserWithSupplierPermissions();

        $this->actingAs($user)->withSession(['setting_id' => $setting->id]);

        // Create first supplier
        $this->post(route('suppliers.store'), [
            'supplier_name' => 'PT MAJU',
            'contact_name' => 'Contact One',
            'supplier_phone' => '081230000001',
            'supplier_email' => 'contact1@example.com',
        ])->assertRedirect(route('suppliers.index'));

        // Attempt to create second supplier with same name
        $response = $this->post(route('suppliers.store'), [
            'supplier_name' => 'PT MAJU',
            'contact_name' => 'Contact Two',
            'supplier_phone' => '081230000002',
            'supplier_email' => 'contact2@example.com',
        ]);

        $response->assertSessionHasErrors(['supplier_name' => 'Nama pemasok sudah digunakan.']);
        $this->assertCount(1, Supplier::where('supplier_name', 'PT MAJU')->get());
    }

    public function test_store_rejects_duplicate_supplier_name_case_insensitive(): void
    {
        $setting = $this->createSetting('Case Insensitive Test');
        $user = $this->createUserWithSupplierPermissions();

        $this->actingAs($user)->withSession(['setting_id' => $setting->id]);

        // Create first supplier
        $this->post(route('suppliers.store'), [
            'supplier_name' => 'PT MAJU',
            'contact_name' => 'Contact One',
            'supplier_phone' => '081230000001',
            'supplier_email' => 'contact1@example.com',
        ])->assertRedirect(route('suppliers.index'));

        // Attempt with different casing
        $response = $this->post(route('suppliers.store'), [
            'supplier_name' => 'pt maju',
            'contact_name' => 'Contact Two',
            'supplier_phone' => '081230000002',
            'supplier_email' => 'contact2@example.com',
        ]);

        $response->assertSessionHasErrors(['supplier_name']);
    }

    public function test_store_rejects_duplicate_supplier_name_with_whitespace(): void
    {
        $setting = $this->createSetting('Whitespace Test');
        $user = $this->createUserWithSupplierPermissions();

        $this->actingAs($user)->withSession(['setting_id' => $setting->id]);

        // Create first supplier
        $this->post(route('suppliers.store'), [
            'supplier_name' => 'PT MAJU',
            'contact_name' => 'Contact One',
            'supplier_phone' => '081230000001',
            'supplier_email' => 'contact1@example.com',
        ])->assertRedirect(route('suppliers.index'));

        // Attempt with extra whitespace
        $response = $this->post(route('suppliers.store'), [
            'supplier_name' => '  PT Maju  ',
            'contact_name' => 'Contact Two',
            'supplier_phone' => '081230000002',
            'supplier_email' => 'contact2@example.com',
        ]);

        $response->assertSessionHasErrors(['supplier_name']);
    }

    public function test_store_accepts_distinct_supplier_names(): void
    {
        $setting = $this->createSetting('Distinct Names Test');
        $user = $this->createUserWithSupplierPermissions();

        $this->actingAs($user)->withSession(['setting_id' => $setting->id]);

        // Create first supplier
        $this->post(route('suppliers.store'), [
            'supplier_name' => 'PT MAJU',
            'contact_name' => 'Contact One',
            'supplier_phone' => '081230000001',
            'supplier_email' => 'contact1@example.com',
        ])->assertRedirect(route('suppliers.index'));

        // Create second supplier with different name
        $response = $this->post(route('suppliers.store'), [
            'supplier_name' => 'PT SEJAHTERA',
            'contact_name' => 'Contact Two',
            'supplier_phone' => '081230000002',
            'supplier_email' => 'contact2@example.com',
        ]);

        $response->assertRedirect(route('suppliers.index'));
        $this->assertCount(2, Supplier::all());
    }

    public function test_store_rejects_duplicate_contact_name(): void
    {
        $setting = $this->createSetting('Contact Name Uniqueness Test');
        $user = $this->createUserWithSupplierPermissions();

        $this->actingAs($user)->withSession(['setting_id' => $setting->id]);

        // Create first supplier
        $this->post(route('suppliers.store'), [
            'supplier_name' => 'PT ONE',
            'contact_name' => 'ANDI WIJAYA',
            'supplier_phone' => '081230000001',
            'supplier_email' => 'contact1@example.com',
        ])->assertRedirect(route('suppliers.index'));

        // Attempt to create second supplier with same contact_name
        $response = $this->post(route('suppliers.store'), [
            'supplier_name' => 'PT TWO',
            'contact_name' => 'ANDI WIJAYA',
            'supplier_phone' => '081230000002',
            'supplier_email' => 'contact2@example.com',
        ]);

        $response->assertSessionHasErrors(['contact_name' => 'Nama kontak sudah digunakan.']);
    }

    public function test_store_allows_blank_contact_name_for_multiple_suppliers(): void
    {
        $setting = $this->createSetting('Blank Contact Name Test');
        $user = $this->createUserWithSupplierPermissions();

        $this->actingAs($user)->withSession(['setting_id' => $setting->id]);

        // Create first supplier without contact_name
        $this->post(route('suppliers.store'), [
            'supplier_name' => 'PT ONE',
            'contact_name' => '',
            'supplier_phone' => '081230000001',
            'supplier_email' => 'contact1@example.com',
        ])->assertRedirect(route('suppliers.index'));

        // Create second supplier without contact_name
        $response = $this->post(route('suppliers.store'), [
            'supplier_name' => 'PT TWO',
            'contact_name' => '',
            'supplier_phone' => '081230000002',
            'supplier_email' => 'contact2@example.com',
        ]);

        $response->assertRedirect(route('suppliers.index'));
        $this->assertCount(2, Supplier::all());
    }

    public function test_store_rejects_cross_setting_duplicate_supplier_name(): void
    {
        $setting1 = $this->createSetting('Setting 1');
        $setting2 = $this->createSetting('Setting 2');
        $user = $this->createUserWithSupplierPermissions();

        // Create supplier in setting 1
        $this->actingAs($user)->withSession(['setting_id' => $setting1->id]);
        $this->post(route('suppliers.store'), [
            'supplier_name' => 'PT MAJU',
            'contact_name' => 'Contact One',
            'supplier_phone' => '081230000001',
            'supplier_email' => 'contact1@example.com',
        ])->assertRedirect(route('suppliers.index'));

        // Try to create same name in setting 2 (should fail because uniqueness is global)
        $this->actingAs($user)->withSession(['setting_id' => $setting2->id]);
        $response = $this->post(route('suppliers.store'), [
            'supplier_name' => 'PT MAJU',
            'contact_name' => 'Contact Two',
            'supplier_phone' => '081230000002',
            'supplier_email' => 'contact2@example.com',
        ]);

        $response->assertSessionHasErrors(['supplier_name']);
        $this->assertCount(1, Supplier::where('supplier_name', 'PT MAJU')->get());
    }

    public function test_update_rejects_collision_with_another_supplier(): void
    {
        $setting = $this->createSetting('Update Collision Test');
        $user = $this->createUserWithSupplierPermissions();

        $this->actingAs($user)->withSession(['setting_id' => $setting->id]);

        // Create two suppliers
        $response1 = $this->post(route('suppliers.store'), [
            'supplier_name' => 'PT MAJU',
            'contact_name' => 'Contact One',
            'supplier_phone' => '081230000001',
            'supplier_email' => 'contact1@example.com',
        ]);
        $response1->assertRedirect(route('suppliers.index'));

        $response2 = $this->post(route('suppliers.store'), [
            'supplier_name' => 'PT SEJAHTERA',
            'contact_name' => 'Contact Two',
            'supplier_phone' => '081230000002',
            'supplier_email' => 'contact2@example.com',
        ]);
        $response2->assertRedirect(route('suppliers.index'));

        $supplier1 = Supplier::where('supplier_name', 'PT MAJU')->first();
        $supplier2 = Supplier::where('supplier_name', 'PT SEJAHTERA')->first();

        // Attempt to change supplier1's name to supplier2's name
        $response = $this->patch(route('suppliers.update', $supplier1->id), [
            'supplier_name' => 'PT SEJAHTERA',
            'contact_name' => 'Contact One Updated',
            'supplier_phone' => '081230000001',
            'supplier_email' => 'contact1@example.com',
        ]);

        $response->assertSessionHasErrors(['supplier_name']);
    }

    public function test_update_allows_unchanged_name(): void
    {
        $setting = $this->createSetting('Update Unchanged Name Test');
        $user = $this->createUserWithSupplierPermissions();

        $this->actingAs($user)->withSession(['setting_id' => $setting->id]);

        // Create supplier
        $this->post(route('suppliers.store'), [
            'supplier_name' => 'PT MAJU',
            'contact_name' => 'Contact One',
            'supplier_phone' => '081230000001',
            'supplier_email' => 'contact1@example.com',
        ])->assertRedirect(route('suppliers.index'));

        $supplier = Supplier::where('supplier_name', 'PT MAJU')->first();

        // Update supplier but keep same name
        $response = $this->patch(route('suppliers.update', $supplier->id), [
            'supplier_name' => 'PT MAJU',
            'contact_name' => 'Contact One Updated',
            'supplier_phone' => '081230000001',
            'supplier_email' => 'contact1@example.com',
        ]);

        $response->assertRedirect(route('suppliers.index'));
        $this->assertCount(1, Supplier::all());
    }

    private function createUserWithSupplierPermissions(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['suppliers.create', 'suppliers.edit', 'suppliers.access']);
        return $user;
    }

    private function createSetting(string $name): Setting
    {
        return Setting::factory()->create([
            'company_name' => $name,
            'company_email' => strtolower(str_replace(' ', '.', $name)) . '@example.com',
            'company_address' => 'Test Address',
            'company_phone' => '081200000000',
        ]);
    }
}
