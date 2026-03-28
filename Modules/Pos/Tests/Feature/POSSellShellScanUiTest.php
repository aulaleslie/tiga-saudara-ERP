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
     * 3.1 + 3.2: Verify scan UI renders helper button, action rail, and active camera button.
     * Assert: helper button present, action rail visible, primary/secondary classes applied,
     * camera button active (not disabled) and present.
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

        // 3.2: Active camera button present (no longer reserved/disabled)
        $response->assertSee('pos-btn-scan-camera');
        $response->assertSee('pos-scan-action-camera');
        // Verify disabled state and reserved data attribute are removed
        $html = $response->getContent();
        $this->assertStringNotContainsString('data-camera-slot="reserved"', $html);
        // Check that camera button is not disabled (should not have disabled attribute on camera button)
        $cameraButtonStart = strpos($html, 'id="pos-btn-scan-camera"');
        $this->assertNotFalse($cameraButtonStart);
        $cameraButtonEnd = strpos($html, '</button>', $cameraButtonStart);
        $cameraButton = substr($html, $cameraButtonStart, $cameraButtonEnd - $cameraButtonStart);
        $this->assertStringNotContainsString('disabled', $cameraButton, 'Camera button should not be disabled');

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

    /**
     * 4.3: Verify camera scanner modal structure is present for camera decode functionality.
     * Assert: scanner modal exists with video element, status text, and control buttons.
     */
    public function test_sell_shell_includes_camera_scanner_modal(): void
    {
        $setting = $this->createSetting('SCAN UI TEST C');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'SCAN UI CASHIER C');

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell'));

        $response->assertOk();

        // 4.3: Scanner modal structure present
        $response->assertSee('pos-camera-scanner-modal');

        // 4.3: Video element for camera feed
        $response->assertSee('pos-camera-video');

        // 4.3: Status display element
        $response->assertSee('pos-camera-scanner-status');

        // 4.3: Control buttons (retry, close/cancel)
        $response->assertSee('pos-camera-scanner-retry');
        $response->assertSee('pos-camera-scanner-close');
        $response->assertSee('pos-camera-scanner-cancel');
    }

    /**
     * 4.3: Verify camera scanner JS module is loaded for decode functionality.
     * Assert: pos-camera-scanner.js and ZXing library are included in page scripts.
     */
    public function test_sell_shell_includes_camera_scanner_scripts(): void
    {
        $setting = $this->createSetting('SCAN UI TEST D');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'SCAN UI CASHIER D');

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell'));

        $response->assertOk();

        $html = $response->getContent();

        // 4.3: Camera scanner JS module included
        $this->assertStringContainsString('pos-camera-scanner.js', $html, 'Camera scanner JS module must be included');

        // 4.3: ZXing barcode decoder library included with deterministic version
        $this->assertStringContainsString('@zxing/library@0.20.0', $html, 'ZXing decoder library must be loaded from deterministic version 0.20.0');
    }

    /**
     * 4.1: Verify camera-open idle behavior does not show premature decode failure.
     * Assert: ZXing is loaded with deterministic version and scanner setup is complete.
     */
    public function test_sell_shell_camera_scanner_no_premature_decode_error(): void
    {
        $setting = $this->createSetting('SCAN UI TEST E');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'SCAN UI CASHIER E');

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell'));

        $response->assertOk();

        $html = $response->getContent();

        // 4.1: Verify scanner modal is present (will use deterministic state values internally)
        $this->assertStringContainsString('id="pos-camera-scanner-modal"', $html);
        $this->assertStringContainsString('id="pos-camera-scanner-status"', $html);

        // 4.1: Verify camera scanner module is loaded
        $this->assertStringContainsString('pos-camera-scanner.js', $html);

        // 4.1: Verify ZXing is loaded with deterministic version for stable initialization (no @latest)
        $this->assertStringContainsString('@zxing/library@0.20.0', $html);
        $this->assertStringNotContainsString('@zxing/library@latest', $html, 'ZXing must use pinned version, not @latest');

        // 4.1: Verify retry and close buttons are present for error recovery
        $this->assertStringContainsString('id="pos-camera-scanner-retry"', $html);
        $this->assertStringContainsString('id="pos-camera-scanner-close"', $html);
    }

    /**
     * 4.2: Verify camera-triggered resolver parity with Enter/helper triggers.
     * Assert: shared resolver function is exposed globally for camera scanner to invoke.
     */
    public function test_sell_shell_camera_resolver_parity_with_enter_trigger(): void
    {
        $setting = $this->createSetting('SCAN UI TEST F');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'SCAN UI CASHIER F');

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell'));

        $response->assertOk();

        $html = $response->getContent();

        // 4.2: Verify camera scanner script is included
        $this->assertStringContainsString('pos-camera-scanner.js', $html);

        // 4.2: Verify shared resolver function is exposed to global scope for camera access
        $this->assertStringContainsString('window.executeScanResolve = executeScanResolve', $html,
            'Shared resolver must be exposed to global window scope so camera scanner can invoke it');

        // 4.2: Verify Enter key handler is present (maintains parity)
        $this->assertStringContainsString('keydown', $html);
        $this->assertStringContainsString('Enter', $html);

        // 4.2: Verify helper button handler is present (maintains parity)
        $this->assertStringContainsString('pos-btn-scan-helper', $html);
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
