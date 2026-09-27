<?php

namespace Modules\Setting\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SettingsWalkInCustomerMappingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        Permission::findOrCreate('settings.access', 'web');
        Permission::findOrCreate('settings.edit', 'web');
    }

    public function test_settings_update_accepts_walk_in_customer_from_same_setting(): void
    {
        $setting = $this->createSetting('BIZ SETTING WALK-IN OK');
        $customer = $this->createCustomer($setting, 'Walk In Same', '08130000001');
        $user = $this->createSettingsEditor($setting, 'SETTING EDITOR SAME');

        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->patch(route('settings.update'), $this->settingsPayload($setting, [
                'pos_walk_in_customer_id' => $customer->id,
            ]))
            ->assertRedirect(route('settings.index'));

        $this->assertDatabaseHas('settings', [
            'id' => $setting->id,
            'pos_walk_in_customer_id' => $customer->id,
        ]);
    }

    public function test_settings_update_accepts_walk_in_customer_from_other_setting(): void
    {
        $setting = $this->createSetting('BIZ SETTING WALK-IN STRICT');
        $otherSetting = $this->createSetting('BIZ SETTING WALK-IN OTHER');
        $otherCustomer = $this->createCustomer($otherSetting, 'Walk In Other', '08130000002');
        $user = $this->createSettingsEditor($setting, 'SETTING EDITOR STRICT');

        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->patch(route('settings.update'), $this->settingsPayload($setting, [
                'pos_walk_in_customer_id' => $otherCustomer->id,
            ]))
            ->assertRedirect(route('settings.index'));

        $this->assertDatabaseHas('settings', [
            'id' => $setting->id,
            'pos_walk_in_customer_id' => $otherCustomer->id,
        ]);
    }

    public function test_settings_update_allows_clearing_walk_in_customer_mapping(): void
    {
        $setting = $this->createSetting('BIZ SETTING WALK-IN CLEAR');
        $customer = $this->createCustomer($setting, 'Walk In Clear', '08130000003');
        $setting->update(['pos_walk_in_customer_id' => $customer->id]);
        $user = $this->createSettingsEditor($setting, 'SETTING EDITOR CLEAR');

        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->patch(route('settings.update'), $this->settingsPayload($setting, [
                'pos_walk_in_customer_id' => null,
            ]))
            ->assertRedirect(route('settings.index'));

        $this->assertDatabaseHas('settings', [
            'id' => $setting->id,
            'pos_walk_in_customer_id' => null,
        ]);
    }

    private function createSetting(string $name): Setting
    {
        return Setting::create([
            'company_name' => $name,
            'company_email' => strtolower(str_replace(' ', '.', $name)) . '@example.com',
            'company_phone' => '0800000000',
            'company_address' => 'Address',
            'default_currency_id' => Currency::query()->value('id'),
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => 'DOC',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
            'purchase_return_prefix_document' => 'PRRN',
            'sale_return_prefix_document' => 'SRRN',
            'is_pkp' => false,
            'pos_enabled' => true,
        ]);
    }

    private function createCustomer(Setting $setting, string $name, string $phone): Customer
    {
        return Customer::create([
            'setting_id' => $setting->id,
            'contact_name' => $name,
            'customer_name' => $name,
            'customer_phone' => $phone,
            'customer_email' => strtolower(str_replace(' ', '.', $name)) . '.' . $setting->id . '@example.com',
            'address' => 'Address',
            'city' => 'City',
            'country' => 'Country',
        ]);
    }

    private function createSettingsEditor(Setting $setting, string $roleName): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $role->syncPermissions(['settings.access', 'settings.edit']);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }
    public function test_settings_update_accepts_walk_in_customer_with_null_setting(): void
    {
        $setting = $this->createSetting('BIZ SETTING WALK-IN NULL');
        // Customer with no setting_id
        $customer = Customer::factory()->create([
            'setting_id' => null,
            'customer_name' => 'Settingless Customer',
            'customer_phone' => '08130000003',
        ]);
        $user = $this->createSettingsEditor($setting, 'SETTING EDITOR NULL');

        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->patch(route('settings.update'), $this->settingsPayload($setting, [
                'pos_walk_in_customer_id' => $customer->id,
            ]))
            ->assertRedirect(route('settings.index'));

        $this->assertDatabaseHas('settings', [
            'id' => $setting->id,
            'pos_walk_in_customer_id' => $customer->id,
        ]);
    }

    public function test_settings_index_does_not_preload_full_customer_list(): void
    {
        $setting = $this->createSetting('BIZ SETTING INDEX');
        $customer1 = $this->createCustomer($setting, 'Cust Alpha', '0811111111');
        $customer2 = $this->createCustomer($setting, 'Cust Beta', '0822222222');
        $user = $this->createSettingsEditor($setting, 'SETTING EDITOR INDEX');

        // When pos_walk_in_customer_id is set to customer1
        $setting->update(['pos_walk_in_customer_id' => $customer1->id]);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('settings.index'));

        $response->assertOk();
        $response->assertViewHas('walkInCustomer', function ($customer) use ($customer1) {
            return $customer && $customer->id === $customer1->id;
        });
        $response->assertSee((string) $customer1->id);
        $response->assertSee($customer1->customer_name);
        $response->assertDontSee($customer2->customer_name);
    }

    public function test_settings_customers_search_returns_matching_customers(): void
    {
        $setting = $this->createSetting('BIZ SETTING SEARCH');
        $customer1 = $this->createCustomer($setting, 'Budi Santoso', '08123456789');
        $customer2 = $this->createCustomer($setting, 'Santoso Group', '08987654321');
        $otherCustomer = $this->createCustomer($setting, 'Andi Wijaya', '08555555555');
        $user = $this->createSettingsEditor($setting, 'SETTING EDITOR SEARCH');

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('settings.customers.search', ['q' => 'Santoso']));

        $response->assertOk()
            ->assertJsonPath('meta.result_count', 2)
            ->assertJsonFragment(['id' => $customer1->id, 'customer_name' => 'BUDI SANTOSO'])
            ->assertJsonFragment(['id' => $customer2->id, 'customer_name' => 'SANTOSO GROUP'])
            ->assertJsonMissing(['id' => $otherCustomer->id]);
    }

    public function test_settings_customers_search_respects_settings_access_authorization(): void
    {
        $setting = $this->createSetting('BIZ SETTING AUTH CHECK');
        $this->createCustomer($setting, 'Budi Santoso', '08123456789');

        // User without settings.access
        $unauthorizedRole = Role::firstOrCreate(['name' => 'UNAUTHORIZED USER']);
        $unauthorizedUser = User::factory()->create();
        $unauthorizedUser->assignRole($unauthorizedRole);
        $unauthorizedUser->settings()->attach($setting->id, ['role_id' => $unauthorizedRole->id]);

        $response = $this->actingAs($unauthorizedUser)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('settings.customers.search', ['q' => 'Budi']));

        $response->assertForbidden();
    }

    public function test_settings_customers_search_succeeds_when_pos_is_disabled_for_business(): void
    {
        $setting = $this->createSetting('BIZ SETTING POS DISABLED');
        $setting->update(['pos_enabled' => false]);
        $customer = $this->createCustomer($setting, 'Customer When Pos Disabled', '08123456789');
        $user = $this->createSettingsEditor($setting, 'SETTING EDITOR NO POS');

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('settings.customers.search', ['q' => 'Customer When']));

        $response->assertOk()
            ->assertJsonFragment(['id' => $customer->id]);
    }

    public function test_settings_customers_search_succeeds_without_pos_permissions(): void
    {
        $setting = $this->createSetting('BIZ SETTING NO POS PERMS');
        $customer = $this->createCustomer($setting, 'Customer No Pos Perm', '08123456789');

        // User has settings.access and settings.edit, but NOT pos.access or pos.returns.view
        $user = $this->createSettingsEditor($setting, 'SETTING EDITOR ONLY');

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('settings.customers.search', ['q' => 'Customer No Pos']));

        $response->assertOk()
            ->assertJsonFragment(['id' => $customer->id]);
    }

    public function test_settings_update_rejects_missing_customer(): void
    {
        $setting = $this->createSetting('BIZ SETTING WALK-IN MISSING');
        $user = $this->createSettingsEditor($setting, 'SETTING EDITOR MISSING');

        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->patch(route('settings.update'), $this->settingsPayload($setting, [
                'pos_walk_in_customer_id' => 999999, // Non-existent ID
            ]))
            ->assertSessionHasErrors(['pos_walk_in_customer_id']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function settingsPayload(Setting $setting, array $overrides = []): array
    {
        return array_merge([
            'company_name' => $setting->company_name,
            'company_email' => $setting->company_email,
            'company_phone' => $setting->company_phone,
            'document_prefix' => $setting->document_prefix,
            'purchase_prefix_document' => $setting->purchase_prefix_document,
            'sale_prefix_document' => $setting->sale_prefix_document,
            'purchase_return_prefix_document' => $setting->purchase_return_prefix_document,
            'sale_return_prefix_document' => $setting->sale_return_prefix_document,
            'company_address' => $setting->company_address,
            'is_pkp' => $setting->is_pkp ? '1' : '0',
            'pos_enabled' => $setting->pos_enabled ? '1' : '0',
            'footer_text' => $setting->footer_text,
        ], $overrides);
    }
}
