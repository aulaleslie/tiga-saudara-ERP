<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class POSSessionRoleTerminalAllocationTest extends TestCase
{
    use RefreshDatabase;

    private int $terminalSequence = 1;

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

        foreach ([
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_floor_staff_can_open_session_without_terminal_selection(): void
    {
        $setting = $this->createSetting('ROLE FLOOR SETTING');
        $user = $this->createUserForSetting($setting, 'Floor Staff', ['pos.access', 'pos.sell', 'pos.sessions.open']);
        $this->createTerminalForSetting($setting);
        $this->enablePaymentMethodForSetting($setting);

        /** @var PosSessionLifecycleService $service */
        $service = app(PosSessionLifecycleService::class);
        $session = $service->openSession(
            $setting->id,
            null,
            $user->id,
            100000,
            ['100000' => 1],
            $user->id
        );

        $this->assertSame('OPEN', $session->status);
        $this->assertNull($session->terminal_id);
    }

    public function test_store_manager_can_open_session_without_terminal_selection(): void
    {
        $setting = $this->createSetting('ROLE MANAGER SETTING');
        $user = $this->createUserForSetting($setting, 'Store Manager', ['pos.access', 'pos.sell', 'pos.sessions.open']);
        $this->createTerminalForSetting($setting);
        $this->enablePaymentMethodForSetting($setting);

        /** @var PosSessionLifecycleService $service */
        $service = app(PosSessionLifecycleService::class);
        $session = $service->openSession(
            $setting->id,
            null,
            $user->id,
            100000,
            ['100000' => 1],
            $user->id
        );

        $this->assertSame('OPEN', $session->status);
        $this->assertNull($session->terminal_id);
    }

    public function test_cashier_role_requires_terminal_selection(): void
    {
        $setting = $this->createSetting('ROLE CASHIER SETTING');
        $user = $this->createUserForSetting($setting, 'Cashier Staff', ['pos.access', 'pos.sell', 'pos.sessions.open']);
        $this->createTerminalForSetting($setting);
        $this->enablePaymentMethodForSetting($setting);

        /** @var PosSessionLifecycleService $service */
        $service = app(PosSessionLifecycleService::class);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Terminal selection is required for cashier sessions.');

        $service->openSession(
            $setting->id,
            null,
            $user->id,
            100000,
            ['100000' => 1],
            $user->id
        );
    }

    public function test_session_conflict_messages_are_actionable_for_user_and_terminal_collisions(): void
    {
        $setting = $this->createSetting('ROLE CONFLICT SETTING');
        $cashierA = $this->createUserForSetting($setting, 'Cashier A', ['pos.access', 'pos.sell', 'pos.sessions.open']);
        $cashierB = $this->createUserForSetting($setting, 'Cashier B', ['pos.access', 'pos.sell', 'pos.sessions.open']);

        $terminalA = $this->createTerminalForSetting($setting);
        $terminalB = $this->createTerminalForSetting($setting);
        $this->enablePaymentMethodForSetting($setting);

        /** @var PosSessionLifecycleService $service */
        $service = app(PosSessionLifecycleService::class);
        $service->openSession($setting->id, $terminalA->id, $cashierA->id, 100000, ['100000' => 1], $cashierA->id);

        try {
            $service->openSession($setting->id, $terminalB->id, $cashierA->id, 100000, ['100000' => 1], $cashierA->id);
            $this->fail('Expected user-level conflict for second active session.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('sudah memiliki sesi POS aktif', $exception->getMessage());
        }

        try {
            $service->openSession($setting->id, $terminalA->id, $cashierB->id, 100000, ['100000' => 1], $cashierB->id);
            $this->fail('Expected terminal ownership conflict for occupied terminal.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('sedang digunakan', $exception->getMessage());
        }
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

    private function createUserForSetting(Setting $setting, string $roleName, array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $role->syncPermissions($permissions);

        $user = User::factory()->create();
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    private function createTerminalForSetting(Setting $setting): PosTerminal
    {
        $sequence = $this->terminalSequence++;

        Location::create([
            'name' => 'ROLE TERMINAL LOC ' . $sequence,
            'setting_id' => $setting->id,
        ]);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'ROLE-TERM-' . $sequence,
            'name' => 'Role Terminal ' . $sequence,
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => 0,
            'require_pickup_supervisor_approval' => true,
            'cash_threshold' => 50000,
        ]);

        return $terminal;
    }

    private function enablePaymentMethodForSetting(Setting $setting): void
    {
        $coa = \Modules\Setting\Entities\ChartOfAccount::create([
            'name' => 'Cash Account ' . $setting->id . '-' . bin2hex(random_bytes(4)),
            'account_number' => '1101-' . $setting->id . '-' . bin2hex(random_bytes(4)),
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
        ]);

        $paymentMethod = \Modules\Setting\Entities\PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $coa->id,
            'is_cash' => true,
        ]);

        \Modules\Setting\Entities\SettingPosPaymentMethod::updateOrCreate(
            [
                'setting_id' => $setting->id,
                'payment_method_id' => $paymentMethod->id,
            ],
            [
                'is_enabled' => true,
            ]
        );
    }
}
