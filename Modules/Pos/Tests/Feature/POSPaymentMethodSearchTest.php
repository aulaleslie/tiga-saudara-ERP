<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\PaymentMethod;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * @group pos-critical-path
 */
class POSPaymentMethodSearchTest extends TestCase
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
            'pos.checkout.payment',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_search_requires_active_session(): void
    {
        $setting = $this->createSetting('PAYMENT METHOD SEARCH NO SESSION');
        $cashier = $this->createUserForSetting($setting, 'PAYMENT METHOD CASHIER NO SESSION', ['pos.access', 'pos.sell', 'pos.checkout.payment']);

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.payment-methods.search'))
            ->assertRedirect(route('pos.sessions.create'));
    }

    public function test_search_is_forbidden_without_checkout_permission(): void
    {
        $setting = $this->createSetting('PAYMENT METHOD NO CHECKOUT');
        $cashier = $this->createUserForSetting($setting, 'PAYMENT METHOD NO CHECKOUT', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
        ]);

        $terminal = $this->createTerminalForSetting($setting);
        PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashier->id,
            'status' => PosSession::STATUS_OPEN,
            'opened_at' => now(),
            'opened_by' => $cashier->id,
            'opening_float_total' => 100000,
            'expected_cash_total' => 100000,
            'active_marker' => 1,
        ]);

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.payment-methods.search'))
            ->assertForbidden();
    }

    public function test_cashier_without_terminal_cannot_search_payment_methods_even_with_checkout_permission(): void
    {
        $setting = $this->createSetting('PAYMENT METHOD CASHIER NO TERMINAL');
        $cashier = $this->createUserForSetting($setting, 'PAYMENT METHOD CASHIER NO TERMINAL', [
            'pos.access',
            'pos.sell',
            'pos.sessions.open',
            'pos.checkout.payment',
        ]);

        PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => null,
            'cashier_user_id' => $cashier->id,
            'status' => PosSession::STATUS_OPEN,
            'opened_at' => now(),
            'opened_by' => $cashier->id,
            'opening_float_total' => 0,
            'expected_cash_total' => 0,
            'active_marker' => 1,
        ]);

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.payment-methods.search'))
            ->assertForbidden()
            ->assertJsonPath('code', 'CHECKOUT_TERMINAL_REQUIRED');
    }

    public function test_returns_only_enabled_methods(): void
    {
        $setting = $this->createSetting('PAYMENT METHOD ENABLED');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'PAYMENT METHOD ENABLED');

        $enabled = $this->createPaymentMethod($setting, 'Cash Enabled', true, true, false);
        $disabled = $this->createPaymentMethod($setting, 'Cash Disabled', true, false, false);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.payment-methods.search'));

        $response->assertOk()
            ->assertJsonCount(1, 'methods');

        $resultIds = collect($response->json('methods'))->pluck('id')->all();
        $this->assertContains($enabled->id, $resultIds);
        $this->assertNotContains($disabled->id, $resultIds);
    }

    public function test_response_includes_required_metadata(): void
    {
        $setting = $this->createSetting('PAYMENT METHOD METADATA');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'PAYMENT METHOD METADATA');

        $cash = $this->createPaymentMethod($setting, 'Tunai', true, true, false);
        $transfer = $this->createPaymentMethod($setting, 'Transfer', false, true, true);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.payment-methods.search'));

        $response->assertOk()
            ->assertJsonCount(2, 'methods');

        // Results are ordered by name. 'TRANSFER' < 'TUNAI'
        
        // Check transfer method structure (index 0)
        $response->assertJsonPath('methods.0.id', $transfer->id)
            ->assertJsonPath('methods.0.name', 'TRANSFER')
            ->assertJsonPath('methods.0.is_cash', false)
            ->assertJsonPath('methods.0.requires_reference', true);

        // Check cash method structure (index 1)
        $response->assertJsonPath('methods.1.id', $cash->id)
            ->assertJsonPath('methods.1.name', 'TUNAI')
            ->assertJsonPath('methods.1.is_cash', true)
            ->assertJsonPath('methods.1.requires_reference', false);
    }

    public function test_search_supports_name_query(): void
    {
        $setting = $this->createSetting('PAYMENT METHOD NAME SEARCH');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'PAYMENT METHOD NAME SEARCH');

        $this->createPaymentMethod($setting, 'Tunai POS', true, true, false);
        $this->createPaymentMethod($setting, 'Transfer Bank', false, true, true);
        $this->createPaymentMethod($setting, 'QRIS', false, true, true);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.payment-methods.search', ['q' => 'transfer']));

        $response->assertOk()
            ->assertJsonCount(1, 'methods')
            ->assertJsonPath('methods.0.name', 'TRANSFER BANK');
    }

    public function test_excludes_inactive_methods(): void
    {
        $setting = $this->createSetting('PAYMENT METHOD INACTIVE');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'PAYMENT METHOD INACTIVE');

        $active = $this->createPaymentMethod($setting, 'Aktif', true, true, false);
        $inactive = PaymentMethod::create([
            'name' => 'Tidak Aktif',
            'coa_id' => $this->createCoa($setting),
            'is_cash' => false,
            'requires_reference' => false,
        ]);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.payment-methods.search'));

        $response->assertOk()
            ->assertJsonCount(1, 'methods')
            ->assertJsonPath('methods.0.id', $active->id);
    }

    public function test_empty_result_when_none_enabled(): void
    {
        $setting = $this->createSetting('PAYMENT METHOD NONE ENABLED');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'PAYMENT METHOD NONE ENABLED');

        // Create only disabled methods
        $this->createPaymentMethod($setting, 'Disabled Method', true, false, false);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.payment-methods.search'));

        $response->assertOk()
            ->assertJsonCount(0, 'methods');
    }

    // --- Helpers ---

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

    /**
     * @return array{0: User, 1: Location}
     */
    private function createCashierAndOpenSession(Setting $setting, string $roleSuffix): array
    {
        $cashier = $this->createUserForSetting(
            $setting,
            $roleSuffix . ' CASHIER',
            ['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.checkout.payment']
        );

        $terminal = $this->createTerminalForSetting($setting);
        $location = SalesLocationResolver::resolve((int) $terminal->setting_id);

        PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashier->id,
            'status' => PosSession::STATUS_OPEN,
            'opened_at' => now(),
            'opened_by' => $cashier->id,
            'opening_float_total' => 100000,
            'expected_cash_total' => 100000,
            'active_marker' => 1,
        ]);

        return [$cashier, $location];
    }

    private function createTerminalForSetting(Setting $setting): PosTerminal
    {
        $sequence = $this->terminalSequence++;

        $location = Location::create([
            'name' => 'PAYMENT METHOD LOC ' . $sequence,
            'setting_id' => $setting->id,
        ]);

        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-PM-' . str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Payment Method Terminal ' . $sequence,
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

    private function createCoa(Setting $setting): int
    {
        return DB::table('chart_of_accounts')->insertGetId([
            'name' => 'COA PM ' . $this->terminalSequence,
            'account_number' => 'ACC-PM-' . $this->terminalSequence++,
            'category' => 'Kas & Bank',
            'setting_id' => $setting->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createPaymentMethod(
        Setting $setting,
        string $name,
        bool $isCash,
        bool $isEnabled,
        bool $requiresReference
    ): PaymentMethod {
        $method = PaymentMethod::create([
            'name' => $name,
            'coa_id' => $this->createCoa($setting),
            'is_cash' => $isCash,
            'requires_reference' => $requiresReference,
        ]);

        \Modules\Setting\Entities\SettingPosPaymentMethod::updateOrCreate(
            ['setting_id' => $setting->id, 'payment_method_id' => $method->id],
            ['is_enabled' => $isEnabled]
        );

        return $method;
    }
}
