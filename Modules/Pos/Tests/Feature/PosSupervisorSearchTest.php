<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Setting\Entities\Setting;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Entities\PosSession;
use Modules\Currency\Entities\Currency;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PosSupervisorSearchTest extends TestCase
{
    use RefreshDatabase;

    private TwoFactorService $twoFactorService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoFactorService = app(TwoFactorService::class);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create default currency
        Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        // Create necessary permissions
        foreach ([
            'pos.access',
            'pos.safeDrops.approve',
            'pos.supervisor.approval',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Create roles
        Role::findOrCreate('Supervisor', 'web');
        Role::findOrCreate('Super Admin', 'web');
    }

    private function createSetting(string $name): Setting
    {
        $currency = Currency::first();
        return Setting::create([
            'company_name' => $name,
            'company_email' => strtolower(str_replace(' ', '.', $name)) . '@example.com',
            'company_phone' => '0800000000',
            'company_address' => 'Test Address',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Test',
        ]);
    }

    private function createTerminal(Setting $setting): PosTerminal
    {
        $terminal = PosTerminal::create([
            'setting_id' => (int) $setting->id,
            'code' => 'TERM1',
            'name' => 'Test Terminal',
            'description' => 'Test Terminal',
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => (int) $terminal->id,
            'approval_threshold' => 1000000,
        ]);

        return $terminal;
    }

    private function createActiveSession(Setting $setting, PosTerminal $terminal, User $user): PosSession
    {
        return PosSession::create([
            'setting_id' => (int) $setting->id,
            'terminal_id' => (int) $terminal->id,
            'opened_by' => (int) $user->id,
            'status' => 'open',
            'opened_at' => now(),
            'expected_cash_total' => 1000000,
        ]);
    }

    private function createSupervisorWithTotp(Setting $setting, string $name, string $email, bool $hasTotp = true): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'is_active' => true,
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ]);

        // Assign to setting with supervisor role
        $supervisorRole = Role::where('name', 'Supervisor')->first();
        $user->settings()->attach($setting->id, ['role_id' => $supervisorRole->id]);

        // Assign role and permissions
        $user->assignRole('Supervisor');
        $user->givePermissionTo(['pos.access', 'pos.safeDrops.approve', 'pos.supervisor.approval']);

        if ($hasTotp) {
            $secret = $this->twoFactorService->generateSecretKey();
            $this->twoFactorService->enableTwoFactor($user, $secret);
        }

        return $user;
    }

    public function test_search_returns_only_totp_enabled_supervisors()
    {
        $setting = $this->createSetting('Test Setting');
        $terminal = $this->createTerminal($setting);
        $user = $this->createUser($setting);
        $session = $this->createActiveSession($setting, $terminal, $user);
        $this->actingAs($user);

        // Create supervisors: one with TOTP, one without
        $this->createSupervisorWithTotp($setting, 'John Supervisor', 'john@example.com', true);
        $this->createSupervisorWithTotp($setting, 'Jane Supervisor', 'jane@example.com', false);

        session(['setting_id' => $setting->id]);

        $response = $this->get(route('pos.sell.supervisors.search', ['q' => 'supervisor']));

        $response->assertOk();
        $response->assertJsonCount(1, 'results');
        $response->assertJsonPath('results.0.name', 'John Supervisor');
    }

    public function test_search_respects_setting_scope()
    {
        $setting1 = $this->createSetting('Setting 1');
        $setting2 = $this->createSetting('Setting 2');

        $terminal1 = $this->createTerminal($setting1);
        $user = $this->createUser($setting1);
        $session = $this->createActiveSession($setting1, $terminal1, $user);
        $this->actingAs($user);

        // Create supervisor in setting1
        $this->createSupervisorWithTotp($setting1, 'Supervisor 1', 'sup1@example.com', true);

        // Create supervisor in setting2
        $this->createSupervisorWithTotp($setting2, 'Supervisor 2', 'sup2@example.com', true);

        session(['setting_id' => $setting1->id]);

        $response = $this->get(route('pos.sell.supervisors.search', ['q' => 'Supervisor']));

        $response->assertOk();
        $response->assertJsonCount(1, 'results');
        $response->assertJsonPath('results.0.name', 'Supervisor 1');
    }

    public function test_search_filters_by_name()
    {
        $setting = $this->createSetting('Test Setting');
        $terminal = $this->createTerminal($setting);
        $user = $this->createUser($setting);
        $session = $this->createActiveSession($setting, $terminal, $user);
        $this->actingAs($user);

        $this->createSupervisorWithTotp($setting, 'Alice Supervisor', 'alice@example.com', true);
        $this->createSupervisorWithTotp($setting, 'Bob Manager', 'bob@example.com', true);

        session(['setting_id' => $setting->id]);

        $response = $this->get(route('pos.sell.supervisors.search', ['q' => 'Alice']));

        $response->assertOk();
        $response->assertJsonCount(1, 'results');
        $response->assertJsonPath('results.0.name', 'Alice Supervisor');
    }

    public function test_search_filters_by_email()
    {
        $setting = $this->createSetting('Test Setting');
        $terminal = $this->createTerminal($setting);
        $user = $this->createUser($setting);
        $session = $this->createActiveSession($setting, $terminal, $user);
        $this->actingAs($user);

        $this->createSupervisorWithTotp($setting, 'John Supervisor', 'john@example.com', true);
        $this->createSupervisorWithTotp($setting, 'Jane Manager', 'jane@example.com', true);

        session(['setting_id' => $setting->id]);

        $response = $this->get(route('pos.sell.supervisors.search', ['q' => 'john@']));

        $response->assertOk();
        $response->assertJsonCount(1, 'results');
        $response->assertJsonPath('results.0.email', 'john@example.com');
    }

    public function test_search_returns_empty_results_for_no_matches()
    {
        $setting = $this->createSetting('Test Setting');
        $terminal = $this->createTerminal($setting);
        $user = $this->createUser($setting);
        $session = $this->createActiveSession($setting, $terminal, $user);
        $this->actingAs($user);

        $this->createSupervisorWithTotp($setting, 'John Supervisor', 'john@example.com', true);

        session(['setting_id' => $setting->id]);

        $response = $this->get(route('pos.sell.supervisors.search', ['q' => 'nonexistent']));

        $response->assertOk();
        $response->assertJsonCount(0, 'results');
        $response->assertJsonPath('meta.result_count', 0);
    }

    public function test_search_returns_empty_results_for_empty_query()
    {
        $setting = $this->createSetting('Test Setting');
        $terminal = $this->createTerminal($setting);
        $user = $this->createUser($setting);
        $session = $this->createActiveSession($setting, $terminal, $user);
        $this->actingAs($user);

        $this->createSupervisorWithTotp($setting, 'John Supervisor', 'john@example.com', true);

        session(['setting_id' => $setting->id]);

        $response = $this->get(route('pos.sell.supervisors.search', ['q' => '   ']));

        $response->assertOk();
        $response->assertJsonCount(0, 'results');
    }

    public function test_search_requires_both_permissions()
    {
        $setting = $this->createSetting('Test Setting');
        $terminal = $this->createTerminal($setting);
        $user = $this->createUser($setting);
        $session = $this->createActiveSession($setting, $terminal, $user);
        $this->actingAs($user);

        // Create supervisor with TOTP but only one permission
        $supervisor = User::create([
            'name' => 'Incomplete Supervisor',
            'email' => 'incomplete@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $supervisorRole = Role::where('name', 'Supervisor')->first();
        $supervisor->settings()->attach($setting->id, ['role_id' => $supervisorRole->id]);
        $supervisor->assignRole('Supervisor');
        $supervisor->givePermissionTo('pos.safeDrops.approve'); // Missing pos.supervisor.approval

        $secret = $this->twoFactorService->generateSecretKey();
        $this->twoFactorService->enableTwoFactor($supervisor, $secret);

        session(['setting_id' => $setting->id]);

        $response = $this->get(route('pos.sell.supervisors.search', ['q' => 'Incomplete']));

        $response->assertOk();
        $response->assertJsonCount(0, 'results');
    }

    public function test_search_respects_limit_parameter()
    {
        $setting = $this->createSetting('Test Setting');
        $terminal = $this->createTerminal($setting);
        $user = $this->createUser($setting);
        $session = $this->createActiveSession($setting, $terminal, $user);
        $this->actingAs($user);

        // Create 5 supervisors
        for ($i = 0; $i < 5; $i++) {
            $this->createSupervisorWithTotp($setting, "Supervisor $i", "sup$i@example.com", true);
        }

        session(['setting_id' => $setting->id]);

        $response = $this->get(route('pos.sell.supervisors.search', ['q' => 'Supervisor', 'limit' => 2]));

        $response->assertOk();
        $response->assertJsonCount(2, 'results');
        $response->assertJsonPath('meta.limit', 2);
    }

    private function createUser(Setting $setting): User
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        // Get or create a default role
        $role = Role::firstOrCreate(['name' => 'User']);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);
        $user->givePermissionTo('pos.access');

        return $user;
    }
}
