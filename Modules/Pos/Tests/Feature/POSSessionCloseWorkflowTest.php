<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Pos\Services\PosSessionLifecycleService;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * @group pos-critical-path
 */
class POSSessionCloseWorkflowTest extends TestCase
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
            'pos.sessions.close',
            'pos.supervisor.approval',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_cashier_can_close_session_successfully(): void
    {
        [$setting, $cashier, $session] = $this->createOpenSession(100000, 5000);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sessions.close.finalize', ['session' => $session->id]), []);

        $response->assertOk()
            ->assertJson([
                'session_id' => $session->id,
                'status' => 'CLOSED',
            ])
            ->assertJsonStructure([
                'session_id',
                'status',
                'closed_at',
            ])
            ->assertJsonMissing('counted_cash_total')
            ->assertJsonMissing('expected_cash_total')
            ->assertJsonMissing('variance_total')
            ->assertJsonMissing('approval_result')
            ->assertJsonMissing('approval_id');

        $this->assertDatabaseHas('pos_sessions', [
            'id' => $session->id,
            'status' => 'CLOSED',
            'closed_by' => $cashier->id,
        ]);
    }

    public function test_cashier_can_close_session_with_optional_reason(): void
    {
        [$setting, $cashier, $session] = $this->createOpenSession(100000, 5000);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sessions.close.finalize', ['session' => $session->id]), [
                'reason' => 'Shift ended early',
            ]);

        $response->assertOk()
            ->assertJson([
                'session_id' => $session->id,
                'status' => 'CLOSED',
            ]);

        $this->assertDatabaseHas('pos_sessions', [
            'id' => $session->id,
            'status' => 'CLOSED',
            'closed_by' => $cashier->id,
        ]);
    }

    public function test_non_cashier_cannot_close_session(): void
    {
        [$setting, $cashier, $session] = $this->createOpenSession(100000, 1000);

        $otherCashier = $this->createUserForSetting(
            $setting,
            'POS CLOSE OTHER CASHIER',
            ['pos.access', 'pos.sell', 'pos.sessions.close']
        );

        $this->actingAs($otherCashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sessions.close.finalize', ['session' => $session->id]), [])
            ->assertForbidden();

        $this->assertDatabaseHas('pos_sessions', [
            'id' => $session->id,
            'status' => 'OPEN',
            'closed_at' => null,
        ]);
    }

    public function test_close_response_includes_only_session_details(): void
    {
        [$setting, $cashier, $session] = $this->createOpenSession(100000, 5000);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sessions.close.finalize', ['session' => $session->id]), []);

        $response->assertOk();

        $data = $response->json();
        $this->assertArrayHasKey('session_id', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('closed_at', $data);
        $this->assertCount(3, $data);
    }

    public function test_close_route_requires_pos_sessions_close_permission(): void
    {
        [$setting, $cashierWithoutClosePermission, $session] = $this->createOpenSession(
            100000,
            1000,
            ['pos.access', 'pos.sell', 'pos.sessions.open']
        );

        $this->actingAs($cashierWithoutClosePermission)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sessions.close.finalize', ['session' => $session->id]), [])
            ->assertForbidden();
    }

    public function test_closed_session_cannot_transact_after_successful_close(): void
    {
        [$setting, $cashier, $session] = $this->createOpenSession(100000, 5000);

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sessions.close.finalize', ['session' => $session->id]), [])
            ->assertOk();

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell'))
            ->assertRedirect(route('pos.sessions.create'))
            ->assertSessionHas('warning', 'Active POS session is required before accessing POS sell screen.');
    }

    private function createOpenSession(
        float $openingFloat,
        float $varianceThreshold,
        array $cashierPermissions = ['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.sessions.close']
    ): array {
        $setting = $this->createSetting('BIZ CLOSE ' . $this->terminalSequence);
        $cashier = $this->createUserForSetting(
            $setting,
            'POS CLOSE CASHIER ' . $this->terminalSequence,
            $cashierPermissions
        );

        $terminal = $this->createTerminalForSetting($setting, $varianceThreshold);

        $coaId = \Illuminate\Support\Facades\DB::table('chart_of_accounts')->insertGetId([
            'name' => 'COA PM ' . $setting->id,
            'account_number' => 'ACC-PM-' . $setting->id . '-' . rand(100, 999),
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $method = \Modules\Setting\Entities\PaymentMethod::create([
            'name' => 'Cash',
            'coa_id' => $coaId,
            'is_cash' => true,
            'requires_reference' => false,
        ]);

        \Modules\Setting\Entities\SettingPosPaymentMethod::updateOrCreate(
            ['setting_id' => $setting->id, 'payment_method_id' => $method->id],
            ['is_enabled' => true]
        );

        /** @var PosSessionLifecycleService $sessionLifecycleService */
        $sessionLifecycleService = app(PosSessionLifecycleService::class);

        $session = $sessionLifecycleService->openSession(
            $setting->id,
            $terminal->id,
            $cashier->id,
            $openingFloat,
            [(string) ((int) $openingFloat) => 1],
            $cashier->id
        );

        return [$setting, $cashier, $session];
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

    private function createUserForSetting(
        Setting $setting,
        string $roleName,
        array $permissions,
        ?string $email = null,
        ?string $plainPassword = null
    ): User {
        $role = Role::firstOrCreate(['name' => $roleName . '-' . $setting->id]);
        $role->syncPermissions($permissions);

        $attributes = [];

        if ($email !== null) {
            $attributes['email'] = $email;
        }

        if ($plainPassword !== null) {
            $attributes['password'] = Hash::make($plainPassword);
        }

        $user = User::factory()->create($attributes);
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    private function createTerminalForSetting(Setting $setting, float $varianceThreshold): PosTerminal
    {
        $sequence = $this->terminalSequence++;

        $location = Location::create([
            'name' => 'CLOSE LOC ' . $sequence,
            'setting_id' => $setting->id,
        ]);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'CLOSE-' . str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            'name' => 'Close Terminal ' . $sequence,
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => $varianceThreshold,
            'cash_threshold' => 50000,
            'require_pickup_supervisor_approval' => true,
        ]);

        return $terminal;
    }
}
