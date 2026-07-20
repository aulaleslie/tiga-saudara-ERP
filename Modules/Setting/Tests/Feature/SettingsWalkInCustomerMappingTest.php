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
