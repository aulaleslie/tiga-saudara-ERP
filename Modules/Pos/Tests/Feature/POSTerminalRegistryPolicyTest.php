<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingSaleLocation;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class POSTerminalRegistryPolicyTest extends TestCase
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

        Permission::findOrCreate('pos.terminals.access', 'web');
        Permission::findOrCreate('pos.terminals.edit', 'web');
    }

    public function test_it_creates_terminal_and_policy_for_allowed_location(): void
    {
        $setting = $this->createSetting('BIZ A');
        $location = Location::create([
            'name' => 'COUNTER A',
            'setting_id' => $setting->id,
        ]);

        $user = $this->createSettingsUser($setting, true);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('pos.terminals.store'), [
                'code' => 'COUNTER-01',
                'name' => 'Kasir Utama',
                'location_id' => $location->id,
                'is_active' => '1',
                'require_session_open' => '1',
                'require_opening_float' => '1',
                'allow_total_only_float_input' => '1',
                'close_variance_approval_threshold' => '50000',
                'cash_threshold' => '2500000',
                'auto_open_drawer_on_session_open' => '1',
                'auto_open_drawer_on_cash_sale' => '1',
                'auto_open_drawer_on_pickup' => '0',
                'auto_open_drawer_on_close' => '0',
                'require_pickup_supervisor_approval' => '1',
            ]);

        $response->assertRedirect(route('pos.terminals.index'));

        $this->assertDatabaseHas('pos_terminals', [
            'setting_id' => $setting->id,
            'code' => 'COUNTER-01',
            'name' => 'KASIR UTAMA',
            'location_id' => $location->id,
            'is_active' => 1,
        ]);

        $terminalId = (int) \DB::table('pos_terminals')->where('code', 'COUNTER-01')->value('id');

        $this->assertDatabaseHas('pos_terminal_policies', [
            'terminal_id' => $terminalId,
            'require_session_open' => 1,
            'require_opening_float' => 1,
            'allow_total_only_float_input' => 1,
            'close_variance_approval_threshold' => 50000,
            'cash_threshold' => 2500000,
            'auto_open_drawer_on_session_open' => 1,
            'auto_open_drawer_on_cash_sale' => 1,
            'require_pickup_supervisor_approval' => 1,
        ]);
    }

    public function test_it_rejects_terminal_creation_when_location_not_in_setting_sale_locations(): void
    {
        $settingA = $this->createSetting('BIZ A');
        $settingB = $this->createSetting('BIZ B');

        $foreignLocation = Location::create([
            'name' => 'BIZ-B-LOC',
            'setting_id' => $settingB->id,
        ]);

        $user = $this->createSettingsUser($settingA, true);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $settingA->id])
            ->from(route('pos.terminals.create'))
            ->post(route('pos.terminals.store'), [
                'code' => 'COUNTER-02',
                'name' => 'Kasir 2',
                'location_id' => $foreignLocation->id,
            ]);

        $response->assertRedirect(route('pos.terminals.create'));
        $response->assertSessionHasErrors(['location_id']);

        $this->assertDatabaseMissing('pos_terminals', [
            'setting_id' => $settingA->id,
            'code' => 'COUNTER-02',
        ]);
    }

    public function test_it_enforces_code_uniqueness_per_setting_and_allows_same_code_other_setting(): void
    {
        $settingA = $this->createSetting('BIZ A');
        $settingB = $this->createSetting('BIZ B');

        $locationA = Location::create([
            'name' => 'A-LOC',
            'setting_id' => $settingA->id,
        ]);

        $locationB = Location::create([
            'name' => 'B-LOC',
            'setting_id' => $settingB->id,
        ]);

        $userA = $this->createSettingsUser($settingA, true);

        $this->actingAs($userA)
            ->withSession(['setting_id' => $settingA->id])
            ->post(route('pos.terminals.store'), [
                'code' => 'COUNTER-01',
                'name' => 'A Terminal',
                'location_id' => $locationA->id,
            ])
            ->assertRedirect(route('pos.terminals.index'));

        $this->actingAs($userA)
            ->withSession(['setting_id' => $settingA->id])
            ->from(route('pos.terminals.create'))
            ->post(route('pos.terminals.store'), [
                'code' => 'COUNTER-01',
                'name' => 'A Terminal 2',
                'location_id' => $locationA->id,
            ])
            ->assertRedirect(route('pos.terminals.create'))
            ->assertSessionHasErrors(['code']);

        $userB = $this->createSettingsUser($settingB, true, 'Manager B');

        $this->actingAs($userB)
            ->withSession(['setting_id' => $settingB->id])
            ->post(route('pos.terminals.store'), [
                'code' => 'COUNTER-01',
                'name' => 'B Terminal',
                'location_id' => $locationB->id,
            ])
            ->assertRedirect(route('pos.terminals.index'));

        $this->assertDatabaseCount('pos_terminals', 2);
    }

    public function test_it_blocks_cross_setting_terminal_edit_access(): void
    {
        $settingA = $this->createSetting('BIZ A');
        $settingB = $this->createSetting('BIZ B');

        $locationB = Location::create([
            'name' => 'B-LOC',
            'setting_id' => $settingB->id,
        ]);

        $terminalId = \DB::table('pos_terminals')->insertGetId([
            'setting_id' => $settingB->id,
            'code' => 'COUNTER-B',
            'name' => 'B TERMINAL',
            'location_id' => $locationB->id,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('pos_terminal_policies')->insert([
            'terminal_id' => $terminalId,
            'require_session_open' => 1,
            'require_opening_float' => 1,
            'allow_total_only_float_input' => 1,
            'close_variance_approval_threshold' => 0,
            'cash_threshold' => null,
            'auto_open_drawer_on_session_open' => 0,
            'auto_open_drawer_on_cash_sale' => 0,
            'auto_open_drawer_on_pickup' => 0,
            'auto_open_drawer_on_close' => 0,
            'require_pickup_supervisor_approval' => 1,
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userA = $this->createSettingsUser($settingA, true);

        $this->actingAs($userA)
            ->withSession(['setting_id' => $settingA->id])
            ->get(route('pos.terminals.edit', $terminalId))
            ->assertNotFound();
    }

    public function test_destroy_deactivates_terminal_without_deleting_row(): void
    {
        $setting = $this->createSetting('BIZ A');
        $location = Location::create([
            'name' => 'A-LOC',
            'setting_id' => $setting->id,
        ]);

        $terminalId = \DB::table('pos_terminals')->insertGetId([
            'setting_id' => $setting->id,
            'code' => 'COUNTER-01',
            'name' => 'A TERMINAL',
            'location_id' => $location->id,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('pos_terminal_policies')->insert([
            'terminal_id' => $terminalId,
            'require_session_open' => 1,
            'require_opening_float' => 1,
            'allow_total_only_float_input' => 1,
            'close_variance_approval_threshold' => 0,
            'cash_threshold' => null,
            'auto_open_drawer_on_session_open' => 0,
            'auto_open_drawer_on_cash_sale' => 0,
            'auto_open_drawer_on_pickup' => 0,
            'auto_open_drawer_on_close' => 0,
            'require_pickup_supervisor_approval' => 1,
            'metadata' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = $this->createSettingsUser($setting, true);

        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->delete(route('pos.terminals.destroy', $terminalId))
            ->assertRedirect(route('pos.terminals.index'));

        $this->assertDatabaseHas('pos_terminals', [
            'id' => $terminalId,
            'is_active' => 0,
        ]);

        $this->assertDatabaseHas('pos_terminal_policies', [
            'terminal_id' => $terminalId,
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
            'pos_enabled' => true,
        ]);
    }

    private function createSettingsUser(Setting $setting, bool $canEdit, string $roleName = 'Manager'): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $role->givePermissionTo('pos.terminals.access');

        if ($canEdit) {
            $role->givePermissionTo('pos.terminals.edit');
        }

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }
}
