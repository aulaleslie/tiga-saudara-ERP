<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PosSafeDropOtpPickupTest extends TestCase
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
            'pos.sell',
            'pos.safeDrops.create',
            'pos.safeDrops.approve',
            'pos.supervisor.approval',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Create roles
        Role::findOrCreate('Cashier', 'web');
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

    private function createTerminal(Setting $setting, string $name): PosTerminal
    {
        $terminal = PosTerminal::create([
            'setting_id' => (int) $setting->id,
            'code' => $name,
            'name' => $name,
            'description' => 'Test Terminal',
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => (int) $terminal->id,
            'approval_threshold' => 1000000,
        ]);

        return $terminal;
    }

    private function createPosSession(Setting $setting, PosTerminal $terminal, User $cashier, float $expectedCash = 500000): PosSession
    {
        return PosSession::create([
            'setting_id' => (int) $setting->id,
            'terminal_id' => (int) $terminal->id,
            'opened_by' => (int) $cashier->id,
            'status' => 'open',
            'opened_at' => now(),
            'expected_cash_total' => $expectedCash,
        ]);
    }

    private function createSupervisorWithTotp(Setting $setting, string $name, string $email): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $supervisorRole = Role::where('name', 'Supervisor')->first();
        $user->settings()->attach($setting->id, ['role_id' => $supervisorRole->id]);
        $user->assignRole('Supervisor');
        $user->givePermissionTo(['pos.access', 'pos.safeDrops.approve', 'pos.supervisor.approval']);

        $secret = $this->twoFactorService->generateSecretKey();
        $this->twoFactorService->enableTwoFactor($user, $secret);

        return $user;
    }

    private function createCashier(Setting $setting, string $name, string $email): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $cashierRole = Role::where('name', 'Cashier')->firstOrCreate(['name' => 'Cashier']);
        $user->settings()->attach($setting->id, ['role_id' => $cashierRole->id]);
        $user->assignRole('Cashier');
        $user->givePermissionTo(['pos.access', 'pos.sell', 'pos.safeDrops.create']);

        return $user;
    }

    public function test_valid_otp_succeeds()
    {
        $setting = $this->createSetting('Test Setting');
        $terminal = $this->createTerminal($setting, 'Terminal 1');
        $cashier = $this->actingAs($this->createCashier($setting, 'Cashier', 'cashier@example.com'));
        $supervisor = $this->createSupervisorWithTotp($setting, 'Supervisor', 'supervisor@example.com');
        $session = $this->createPosSession($setting, $terminal, $cashier, 500000);

        session(['setting_id' => $setting->id]);

        // Get a valid OTP code
        $validOtp = app(TwoFactorService::class)->google2fa->getCurrentOtp($supervisor->two_factor_secret);

        $response = $this->postJson(route('pos.sessions.pickup', $session->id), [
            'amount' => 100000,
            'supervisor_id' => $supervisor->id,
            'otp_code' => $validOtp,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Pengambilan kas berhasil.');
    }

    public function test_invalid_otp_returns_422()
    {
        $setting = $this->createSetting('Test Setting');
        $terminal = $this->createTerminal($setting, 'Terminal 1');
        $cashier = $this->actingAs($this->createCashier($setting, 'Cashier', 'cashier@example.com'));
        $supervisor = $this->createSupervisorWithTotp($setting, 'Supervisor', 'supervisor@example.com');
        $session = $this->createPosSession($setting, $terminal, $cashier, 500000);

        session(['setting_id' => $setting->id]);

        $response = $this->postJson(route('pos.sessions.pickup', $session->id), [
            'amount' => 100000,
            'supervisor_id' => $supervisor->id,
            'otp_code' => '000000',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Kode OTP tidak valid atau telah kadaluarsa.');
    }

    public function test_supervisor_without_totp_returns_422()
    {
        $setting = $this->createSetting('Test Setting');
        $terminal = $this->createTerminal($setting, 'Terminal 1');
        $cashier = $this->actingAs($this->createCashier($setting, 'Cashier', 'cashier@example.com'));
        
        // Create supervisor without TOTP
        $supervisor = User::create([
            'name' => 'No TOTP Supervisor',
            'email' => 'notp@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $supervisorRole = Role::where('name', 'Supervisor')->first();
        $supervisor->settings()->attach($setting->id, ['role_id' => $supervisorRole->id]);
        $supervisor->assignRole('Supervisor');
        $supervisor->givePermissionTo(['pos.access', 'pos.safeDrops.approve', 'pos.supervisor.approval']);

        $session = $this->createPosSession($setting, $terminal, $cashier, 500000);

        session(['setting_id' => $setting->id]);

        $response = $this->postJson(route('pos.sessions.pickup', $session->id), [
            'amount' => 100000,
            'supervisor_id' => $supervisor->id,
            'otp_code' => '123456',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Supervisor belum mengaktifkan autentikasi dua faktor.');
    }

    public function test_nonexistent_supervisor_returns_422()
    {
        $setting = $this->createSetting('Test Setting');
        $terminal = $this->createTerminal($setting, 'Terminal 1');
        $cashier = $this->actingAs($this->createCashier($setting, 'Cashier', 'cashier@example.com'));
        $session = $this->createPosSession($setting, $terminal, $cashier, 500000);

        session(['setting_id' => $setting->id]);

        $response = $this->postJson(route('pos.sessions.pickup', $session->id), [
            'amount' => 100000,
            'supervisor_id' => 99999,
            'otp_code' => '123456',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Supervisor tidak ditemukan atau tidak aktif.');
    }

    public function test_amount_exceeding_expected_cash_returns_422()
    {
        $setting = $this->createSetting('Test Setting');
        $terminal = $this->createTerminal($setting, 'Terminal 1');
        $cashier = $this->actingAs($this->createCashier($setting, 'Cashier', 'cashier@example.com'));
        $supervisor = $this->createSupervisorWithTotp($setting, 'Supervisor', 'supervisor@example.com');
        $session = $this->createPosSession($setting, $terminal, $cashier, 100000); // Only 100k expected

        session(['setting_id' => $setting->id]);

        $validOtp = app(TwoFactorService::class)->google2fa->getCurrentOtp($supervisor->two_factor_secret);

        $response = $this->postJson(route('pos.sessions.pickup', $session->id), [
            'amount' => 500000, // Try to withdraw 500k
            'supervisor_id' => $supervisor->id,
            'otp_code' => $validOtp,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Jumlah pengambilan tidak boleh melebihi ekspektasi kas.');
    }
}
