<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * @group pos-critical-path
 */
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

    public function test_it_creates_terminal_and_policy_without_location_configuration(): void
    {
        $setting = $this->createSetting('BIZ A');

        $user = $this->createSettingsUser($setting, true);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('pos.terminals.store'), [
                'code' => 'COUNTER-01',
                'name' => 'Kasir Utama',
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

    public function test_create_form_does_not_render_location_selector(): void
    {
        $setting = $this->createSetting('BIZ A');
        $user = $this->createSettingsUser($setting, true);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.terminals.create'));

        $response->assertOk();
        $response->assertDontSee('name="location_id"', false);
    }

    public function test_it_enforces_code_uniqueness_per_setting_and_allows_same_code_other_setting(): void
    {
        $settingA = $this->createSetting('BIZ A');
        $settingB = $this->createSetting('BIZ B');

        $userA = $this->createSettingsUser($settingA, true);

        $this->actingAs($userA)
            ->withSession(['setting_id' => $settingA->id])
            ->post(route('pos.terminals.store'), [
                'code' => 'COUNTER-01',
                'name' => 'A Terminal',
            ])
            ->assertRedirect(route('pos.terminals.index'));

        $this->actingAs($userA)
            ->withSession(['setting_id' => $settingA->id])
            ->from(route('pos.terminals.create'))
            ->post(route('pos.terminals.store'), [
                'code' => 'COUNTER-01',
                'name' => 'A Terminal 2',
            ])
            ->assertRedirect(route('pos.terminals.create'))
            ->assertSessionHasErrors(['code']);

        $userB = $this->createSettingsUser($settingB, true, 'Manager B');

        $this->actingAs($userB)
            ->withSession(['setting_id' => $settingB->id])
            ->post(route('pos.terminals.store'), [
                'code' => 'COUNTER-01',
                'name' => 'B Terminal',
            ])
            ->assertRedirect(route('pos.terminals.index'));

        $this->assertDatabaseCount('pos_terminals', 2);
    }

    public function test_it_blocks_cross_setting_terminal_edit_access(): void
    {
        $settingA = $this->createSetting('BIZ A');
        $settingB = $this->createSetting('BIZ B');

        $terminal = PosTerminal::create([
            'setting_id' => $settingB->id,
            'code' => 'COUNTER-B',
            'name' => 'B TERMINAL',
            'is_active' => 1,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
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
        ]);

        $userA = $this->createSettingsUser($settingA, true);

        $this->actingAs($userA)
            ->withSession(['setting_id' => $settingA->id])
            ->get(route('pos.terminals.edit', $terminal->id))
            ->assertNotFound();
    }

    public function test_destroy_deactivates_terminal_without_deleting_row(): void
    {
        $setting = $this->createSetting('BIZ A');
        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'COUNTER-01',
            'name' => 'A TERMINAL',
            'is_active' => 1,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
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
        ]);

        $user = $this->createSettingsUser($setting, true);

        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->delete(route('pos.terminals.destroy', $terminal->id))
            ->assertRedirect(route('pos.terminals.index'));

        $this->assertDatabaseHas('pos_terminals', [
            'id' => $terminal->id,
            'is_active' => 0,
        ]);

        $this->assertDatabaseHas('pos_terminal_policies', [
            'terminal_id' => $terminal->id,
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
