<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * @group pos-critical-path
 */
class POSSellShellScanUiTest extends TestCase
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

    /**
     * 3.1 + 3.2: Verify scan UI renders helper button, action rail, and reserved camera slot.
     * Assert: helper button present, action rail visible, primary/secondary classes applied,
     * camera slot reserved with disabled state.
     */
    public function test_sell_shell_renders_scan_helper_button_and_action_rail(): void
    {
        $setting = $this->createSetting('SCAN UI TEST A');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'SCAN UI CASHIER A');

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell'));

        $response->assertOk();

        // 3.1: Helper button present with correct ID and text
        $response->assertSee('pos-btn-scan-helper');
        $response->assertSee('Pindai');

        // 3.2: Action rail container present
        $response->assertSee('pos-scan-action-rail');

        // 3.2: Primary button class present on helper
        $response->assertSee('btn-primary');
        $response->assertSee('pos-scan-action-primary');

        // 3.2: Secondary buttons present (Cari Produk)
        $response->assertSee('pos-scan-action-secondary');

        // 3.2: Reserved camera slot present with disabled state and data attribute
        $response->assertSee('data-camera-slot');
        $response->assertSee('pos-scan-action-camera');
        $response->assertSee('disabled');
        $response->assertSee('Segera hadir');

        // 3.2: Cari Produk button still present
        $response->assertSee('pos-btn-cari-produk');
    }

    /**
     * 3.2: Verify action-rail order: helper button (primary) appears before Cari Produk (secondary).
     * This ensures visual hierarchy is preserved in rendered HTML.
     */
    public function test_sell_shell_scan_action_rail_order_primary_before_secondary(): void
    {
        $setting = $this->createSetting('SCAN UI TEST B');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'SCAN UI CASHIER B');

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell'));

        $response->assertOk();

        $html = $response->getContent();

        // Find positions of helper and Cari Produk buttons
        $helperPos = strpos($html, 'id="pos-btn-scan-helper"');
        $cariProdukPos = strpos($html, 'id="pos-btn-cari-produk"');

        // Assert helper appears before Cari Produk
        $this->assertNotFalse($helperPos, 'Helper button not found in HTML');
        $this->assertNotFalse($cariProdukPos, 'Cari Produk button not found in HTML');
        $this->assertLessThan($cariProdukPos, $helperPos, 'Helper button must appear before Cari Produk button');
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
            'name' => 'SCAN UI LOC ' . $sequence,
            'setting_id' => $setting->id,
        ]);

        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-SCAN-UI-' . str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Scan UI Terminal ' . $sequence,
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
}
