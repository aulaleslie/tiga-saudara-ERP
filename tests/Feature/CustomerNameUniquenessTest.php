<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CustomerNameUniquenessTest extends TestCase
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

        foreach (['customers.create', 'customers.edit', 'customers.access'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    protected Currency $currency;

    public function test_store_rejects_duplicate_customer_name(): void
    {
        $setting = $this->createSetting('Customer Name Uniqueness Test');
        $user = $this->createUserWithCustomerPermissions();

        $this->actingAs($user)->withSession(['setting_id' => $setting->id]);

        // Create first customer
        $this->post(route('customers.store'), [
            'customer_name' => 'Toko ABC',
            'contact_name' => 'Contact One',
            'customer_phone' => '081230000001',
            'customer_email' => '',
        ])->assertRedirect(route('customers.index'));

        // Attempt to create second customer with same name
        $response = $this->post(route('customers.store'), [
            'customer_name' => 'Toko ABC',
            'contact_name' => 'Contact Two',
            'customer_phone' => '081230000002',
            'customer_email' => '',
        ]);

        $response->assertSessionHasErrors(['customer_name' => 'Nama pelanggan sudah digunakan.']);
        $this->assertCount(1, Customer::where('customer_name', 'TOKO ABC')->get());
    }

    public function test_store_rejects_duplicate_customer_name_case_insensitive(): void
    {
        $setting = $this->createSetting('Case Insensitive Test');
        $user = $this->createUserWithCustomerPermissions();

        $this->actingAs($user)->withSession(['setting_id' => $setting->id]);

        // Create first customer
        $this->post(route('customers.store'), [
            'customer_name' => 'Toko ABC',
            'contact_name' => 'Contact One',
            'customer_phone' => '081230000001',
            'customer_email' => '',
        ])->assertRedirect(route('customers.index'));

        // Attempt with different casing
        $response = $this->post(route('customers.store'), [
            'customer_name' => 'toko abc',
            'contact_name' => 'Contact Two',
            'customer_phone' => '081230000002',
            'customer_email' => '',
        ]);

        $response->assertSessionHasErrors(['customer_name']);
    }

    public function test_store_rejects_duplicate_customer_name_with_whitespace(): void
    {
        $setting = $this->createSetting('Whitespace Test');
        $user = $this->createUserWithCustomerPermissions();

        $this->actingAs($user)->withSession(['setting_id' => $setting->id]);

        // Create first customer
        $this->post(route('customers.store'), [
            'customer_name' => 'Toko ABC',
            'contact_name' => 'Contact One',
            'customer_phone' => '081230000001',
            'customer_email' => '',
        ])->assertRedirect(route('customers.index'));

        // Attempt with extra whitespace
        $response = $this->post(route('customers.store'), [
            'customer_name' => '  Toko ABC  ',
            'contact_name' => 'Contact Two',
            'customer_phone' => '081230000002',
            'customer_email' => '',
        ]);

        $response->assertSessionHasErrors(['customer_name']);
    }

    public function test_store_accepts_distinct_customer_names(): void
    {
        $setting = $this->createSetting('Distinct Names Test');
        $user = $this->createUserWithCustomerPermissions();

        $this->actingAs($user)->withSession(['setting_id' => $setting->id]);

        // Create first customer
        $this->post(route('customers.store'), [
            'customer_name' => 'Toko ABC',
            'contact_name' => 'Contact One',
            'customer_phone' => '081230000001',
            'customer_email' => '',
        ])->assertRedirect(route('customers.index'));

        // Create second customer with different name
        $response = $this->post(route('customers.store'), [
            'customer_name' => 'Toko XYZ',
            'contact_name' => 'Contact Two',
            'customer_phone' => '081230000002',
            'customer_email' => '',
        ]);

        $response->assertRedirect(route('customers.index'));
        $this->assertCount(2, Customer::all());
    }

    public function test_store_rejects_duplicate_contact_name(): void
    {
        $setting = $this->createSetting('Contact Name Uniqueness Test');
        $user = $this->createUserWithCustomerPermissions();

        $this->actingAs($user)->withSession(['setting_id' => $setting->id]);

        // Create first customer
        $this->post(route('customers.store'), [
            'customer_name' => 'Toko One',
            'contact_name' => 'Budi Santoso',
            'customer_phone' => '081230000001',
            'customer_email' => '',
        ])->assertRedirect(route('customers.index'));

        // Attempt to create second customer with same contact_name
        $response = $this->post(route('customers.store'), [
            'customer_name' => 'Toko Two',
            'contact_name' => 'Budi Santoso',
            'customer_phone' => '081230000002',
            'customer_email' => '',
        ]);

        $response->assertSessionHasErrors(['contact_name' => 'Nama kontak sudah digunakan.']);
    }

    public function test_store_allows_blank_contact_name_for_multiple_customers(): void
    {
        $setting = $this->createSetting('Blank Contact Name Test');
        $user = $this->createUserWithCustomerPermissions();

        $this->actingAs($user)->withSession(['setting_id' => $setting->id]);

        // Create first customer without contact_name
        $this->post(route('customers.store'), [
            'customer_name' => 'Toko One',
            'contact_name' => '',
            'customer_phone' => '081230000001',
            'customer_email' => '',
        ])->assertRedirect(route('customers.index'));

        // Create second customer without contact_name
        $response = $this->post(route('customers.store'), [
            'customer_name' => 'Toko Two',
            'contact_name' => '',
            'customer_phone' => '081230000002',
            'customer_email' => '',
        ]);

        $response->assertRedirect(route('customers.index'));
        $this->assertCount(2, Customer::all());
    }

    public function test_store_rejects_cross_setting_duplicate_customer_name(): void
    {
        $setting1 = $this->createSetting('Setting 1');
        $setting2 = $this->createSetting('Setting 2');
        $user = $this->createUserWithCustomerPermissions();

        // Create customer in setting 1
        $this->actingAs($user)->withSession(['setting_id' => $setting1->id]);
        $this->post(route('customers.store'), [
            'customer_name' => 'Toko ABC',
            'contact_name' => 'Contact One',
            'customer_phone' => '081230000001',
            'customer_email' => '',
        ])->assertRedirect(route('customers.index'));

        // Try to create same name in setting 2 (should fail)
        $this->actingAs($user)->withSession(['setting_id' => $setting2->id]);
        $response = $this->post(route('customers.store'), [
            'customer_name' => 'Toko ABC',
            'contact_name' => 'Contact Two',
            'customer_phone' => '081230000002',
            'customer_email' => '',
        ]);

        $response->assertSessionHasErrors(['customer_name']);
        $this->assertCount(1, Customer::where('customer_name', 'TOKO ABC')->get());
    }

    public function test_update_rejects_collision_with_another_customer(): void
    {
        $setting = $this->createSetting('Update Collision Test');
        $user = $this->createUserWithCustomerPermissions();

        $this->actingAs($user)->withSession(['setting_id' => $setting->id]);

        // Create two customers
        $response1 = $this->post(route('customers.store'), [
            'customer_name' => 'Toko ABC',
            'contact_name' => 'Contact One',
            'customer_phone' => '081230000001',
            'customer_email' => '',
        ]);
        $response1->assertRedirect(route('customers.index'));

        $response2 = $this->post(route('customers.store'), [
            'customer_name' => 'Toko XYZ',
            'contact_name' => 'Contact Two',
            'customer_phone' => '081230000002',
            'customer_email' => '',
        ]);
        $response2->assertRedirect(route('customers.index'));

        $customerA = Customer::where('customer_name', 'TOKO ABC')->first();
        $customerB = Customer::where('customer_name', 'TOKO XYZ')->first();

        // Try to rename customer B to customer A's name
        $response = $this->put(route('customers.update', $customerB->id), [
            'customer_name' => 'Toko ABC',
            'contact_name' => 'Contact Two',
            'customer_phone' => '081230000002',
            'customer_email' => '',
        ]);

        $response->assertSessionHasErrors(['customer_name' => 'Nama pelanggan sudah digunakan.']);
    }

    public function test_update_allows_unchanged_customer_name(): void
    {
        $setting = $this->createSetting('Update Unchanged Name Test');
        $user = $this->createUserWithCustomerPermissions();

        $this->actingAs($user)->withSession(['setting_id' => $setting->id]);

        // Create customer
        $this->post(route('customers.store'), [
            'customer_name' => 'Toko ABC',
            'contact_name' => 'Contact One',
            'customer_phone' => '081230000001',
            'customer_email' => 'test@example.com',
        ])->assertRedirect(route('customers.index'));

        $customer = Customer::where('customer_name', 'TOKO ABC')->first();

        // Update customer without changing name
        $response = $this->put(route('customers.update', $customer->id), [
            'customer_name' => 'Toko ABC',
            'contact_name' => 'Contact Updated',
            'customer_phone' => '081230000001',
            'customer_email' => 'test@example.com',
        ]);

        $response->assertRedirect(route('customers.index'));
        $customer->refresh();
        $this->assertEquals('CONTACT UPDATED', $customer->contact_name);
    }

    private function createUserWithCustomerPermissions(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['customers.create', 'customers.edit', 'customers.access']);

        return $user;
    }

    private function createSetting(string $name): Setting
    {
        return Setting::factory()->create([
            'company_name' => $name,
            'company_email' => strtolower(str_replace(' ', '.', $name)) . '@example.com',
            'company_address' => 'Test Address',
            'company_phone' => '123456789',
            'default_currency_id' => $this->currency->id,
            'default_currency_position' => 'prefix',
        ]);
    }
}
