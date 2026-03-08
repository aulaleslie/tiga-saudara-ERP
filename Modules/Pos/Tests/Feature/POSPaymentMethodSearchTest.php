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
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_search_requires_active_session(): void
    {
        $setting = $this->createSetting('PAYMENT METHOD SEARCH NO SESSION');
        $cashier = $this->createUserForSetting($setting, 'PAYMENT METHOD CASHIER NO SESSION', ['pos.access', 'pos.sell']);

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.payment-methods.search'))
            ->assertRedirect(route('pos.sessions.create'));
    }

    public function test_returns_only_pos_available_methods(): void
    {
        $setting = $this->createSetting('PAYMENT METHOD POS AVAILABLE');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'PAYMENT METHOD POS AVAILABLE');

        $available = $this->createPaymentMethod($setting, 'Cash Available', true, true, false);
        $notAvailable = $this->createPaymentMethod($setting, 'Cash Not Available', true, false, false);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.payment-methods.search'));

        $response->assertOk()
            ->assertJsonCount(1, 'results');

        $resultIds = collect($response->json('results'))->pluck('id')->all();
        $this->assertContains($available->id, $resultIds);
        $this->assertNotContains($notAvailable->id, $resultIds);
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
            ->assertJsonCount(2, 'results');

        // Check cash method structure
        $response->assertJsonPath('results.0.id', $cash->id)
            ->assertJsonPath('results.0.name', 'Tunai')
            ->assertJsonPath('results.0.is_cash', true)
            ->assertJsonPath('results.0.requires_reference', false);

        // Check transfer method structure  
        $response->assertJsonPath('results.1.id', $transfer->id)
            ->assertJsonPath('results.1.name', 'Transfer')
            ->assertJsonPath('results.1.is_cash', false)
            ->assertJsonPath('results.1.requires_reference', true);
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
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.name', 'Transfer Bank');
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
            'is_available_in_pos' => false, // Not available
            'requires_reference' => false,
        ]);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.payment-methods.search'));

        $response->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.id', $active->id);
    }

    public function test_empty_result_when_none_available(): void
    {
        $setting = $this->createSetting('PAYMENT METHOD NONE AVAILABLE');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'PAYMENT METHOD NONE AVAILABLE');

        // Create only non-POS methods
        $this->createPaymentMethod($setting, 'Offline Method', true, false, false);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.payment-methods.search'));

        $response->assertOk()
            ->assertJsonCount(0, 'results');
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
            ['pos.access', 'pos.sell', 'pos.sessions.open']
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
        bool $isAvailableInPos,
        bool $requiresReference
    ): PaymentMethod {
        return PaymentMethod::create([
            'name' => $name,
            'coa_id' => $this->createCoa($setting),
            'is_cash' => $isCash,
            'is_available_in_pos' => $isAvailableInPos,
            'requires_reference' => $requiresReference,
        ]);
    }
}
