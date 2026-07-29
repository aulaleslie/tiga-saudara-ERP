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
     * 4.3 + 3.1: Verify camera scanner modal structure is present for continuous camera decode functionality.
     * Assert: scanner modal exposes persistent guidance, status area, scan lane, and explicit close controls.
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

        // 3.1: Persistent in-session status area and guidance copy
        $response->assertSee('pos-camera-scanner-session-status');
        $response->assertSee('pos-camera-scanner-status-chip');
        $response->assertSee('pos-camera-scanner-status');
        $response->assertSee('pos-camera-scanner-detail');
        $response->assertSee('pos-camera-scan-lane');
        $response->assertSee('jalur scan');

        // 4.3: Control buttons (retry, explicit close)
        $response->assertSee('pos-camera-scanner-retry');
        $response->assertSee('pos-camera-scanner-close');
        $response->assertSee('pos-camera-scanner-cancel');
        $response->assertSee('Selesai Scan');

        // Debug panel element present in markup but not active by default
        $html = $response->getContent();
        $this->assertStringContainsString('id="pos-camera-scanner-debug"', $html,
            'Debug panel element must be present in scanner modal markup');
        $this->assertStringNotContainsString('id="pos-camera-scanner-debug" class="pos-scanner-debug-panel is-active"', $html,
            'Debug panel must not be active in server-rendered HTML');
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

        // 4.3: ZXing barcode decoder library included from pinned local vendor bundle
        $this->assertStringContainsString('vendor/zxing/index.min.js', $html, 'ZXing decoder library must be loaded from pinned local vendor bundle');
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

        // 4.1: Verify ZXing is loaded from the pinned local vendor bundle
        $this->assertStringContainsString('vendor/zxing/index.min.js', $html);
        $this->assertStringNotContainsString('@zxing/library@latest', $html, 'ZXing must not use a floating latest build');

        // 4.1: Verify retry and close buttons are present for error recovery
        $this->assertStringContainsString('id="pos-camera-scanner-retry"', $html);
        $this->assertStringContainsString('id="pos-camera-scanner-close"', $html);
        $this->assertStringContainsString('data-backdrop="static"', $html);
        $this->assertStringContainsString('data-keyboard="false"', $html);
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

        // 4.2: Resolver now returns outcome metadata so the camera session can stay open across results
        $this->assertStringContainsString("outcome: 'product_exact'", $html);
        $this->assertStringContainsString("outcome: 'serial_exact'", $html);
        $this->assertStringContainsString("outcome: 'not_found'", $html);
        $this->assertStringContainsString("outcome: 'resolver_error'", $html);

        // 4.2: Verify Enter key handler is present (maintains parity)
        $this->assertStringContainsString('keydown', $html);
        $this->assertStringContainsString('Enter', $html);

        // 4.2: Verify helper button handler is present (maintains parity)
        $this->assertStringContainsString('pos-btn-scan-helper', $html);
    }

    public function test_sell_shell_camera_scanner_markup_supports_continuous_session_feedback(): void
    {
        $setting = $this->createSetting('SCAN UI TEST G');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'SCAN UI CASHIER G');

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell'));

        $response->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('pos-camera-scanner-session-status', $html);
        $this->assertStringContainsString('data-status-tone="ready"', $html);
        $this->assertStringContainsString('Scanner virtual aktif', $html);
        $this->assertStringContainsString('Input scan tetap sinkron', $html);
        $this->assertStringContainsString('Sesi tetap terbuka sampai kasir menutup scanner.', $html);
    }

    /**
     * 3.2: Verify decode-path rollback: scanner JS must not contain ASSUME_GS1 hint and must use 1280x720 constraints.
     * Assert invariants that guard the regression surface from re-introduction.
     */
    public function test_camera_scanner_js_does_not_use_assume_gs1_or_high_res_constraints(): void
    {
        $scannerPath = public_path('js/pos-camera-scanner.js');
        $this->assertFileExists($scannerPath, 'Camera scanner JS file must exist');

        $source = file_get_contents($scannerPath);

        $this->assertStringNotContainsString('ASSUME_GS1', $source,
            'ASSUME_GS1 hint must not be present in the camera scanner decode path');

        $this->assertStringNotContainsString('ideal: 1920', $source,
            'Camera constraints must not request 1920px width (regression profile)');

        $this->assertStringNotContainsString('ideal: 1080', $source,
            'Camera constraints must not request 1080px height (regression profile)');

        $this->assertStringContainsString('ideal: 1280', $source,
            'Camera constraints must request 1280px ideal width');

        $this->assertStringContainsString('ideal: 720', $source,
            'Camera constraints must request 720px ideal height');
    }

    /**
     * 3.1 + 3.2: Verify scanner JS exposes post-start camera diagnostics and scan-readiness gating.
     * Assert the mobile recovery diagnostics are part of the shipped scanner source.
     */
    public function test_camera_scanner_js_tracks_capabilities_constraints_and_video_readiness(): void
    {
        $scannerPath = public_path('js/pos-camera-scanner.js');
        $this->assertFileExists($scannerPath, 'Camera scanner JS file must exist');

        $source = file_get_contents($scannerPath);

        $this->assertStringContainsString('trackCapabilitiesSummary', $source,
            'Scanner debug state must track active video-track capabilities');

        $this->assertStringContainsString('trackSettingsSummary', $source,
            'Scanner debug state must track active video-track settings');

        $this->assertStringContainsString('requestedPostStartConstraints', $source,
            'Scanner debug state must capture requested post-start constraints');

        $this->assertStringContainsString('postStartConstraintResults', $source,
            'Scanner debug state must capture post-start constraint outcomes');

        $this->assertStringContainsString('waitForVideoReadiness', $source,
            'Scanner pipeline must wait for video readiness before decode start');

        $this->assertStringContainsString('applyPostStartVideoConstraints', $source,
            'Scanner pipeline must apply post-start camera constraints before decode start');

        $this->assertStringContainsString('isVideoScanReady', $source,
            'Scanner must gate decode startup on a scan-ready video stream');
    }

    /**
     * 3.2: Verify re-arm and duplicate suppression constants are present in scanner JS source.
     * Assert: SAME_CODE_SUPPRESSION_MS and REARM_COOLDOWN_MS constants are defined.
     */
    public function test_camera_scanner_js_defines_duplicate_suppression_and_rearm_constants(): void
    {
        $scannerPath = public_path('js/pos-camera-scanner.js');
        $this->assertFileExists($scannerPath, 'Camera scanner JS file must exist');

        $source = file_get_contents($scannerPath);

        $this->assertStringContainsString('SAME_CODE_SUPPRESSION_MS', $source,
            'Duplicate suppression window constant must be defined in scanner JS');

        $this->assertStringContainsString('REARM_COOLDOWN_MS', $source,
            'Re-arm cooldown constant must be defined in scanner JS');

        $this->assertStringContainsString('scheduleRearm', $source,
            'scheduleRearm function must be present for continuous session re-arm behavior');
    }

    /**
     * 3.2: Verify debug panel is present in modal but hidden by default in server-rendered markup.
     * Assert the is-active class is not pre-applied (debug flag must be off by default).
     */
    public function test_camera_scanner_debug_panel_hidden_by_default(): void
    {
        $setting = $this->createSetting('SCAN UI TEST H');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'SCAN UI CASHIER H');

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell'));

        $response->assertOk();

        $html = $response->getContent();

        // Debug panel element must exist
        $this->assertStringContainsString('id="pos-camera-scanner-debug"', $html,
            'Debug panel element must be present in scanner modal markup');

        // Must have the base CSS class but NOT is-active (hidden until JS enables it)
        $this->assertStringContainsString('pos-scanner-debug-panel', $html,
            'Debug panel must carry its CSS class in markup');

        $this->assertStringNotContainsString('pos-scanner-debug-panel is-active', $html,
            'Debug panel must not have is-active class in server-rendered HTML — it is JS-only');

        $this->assertStringContainsString('pos-scanner-debug-grid', $html,
            'Debug panel markup/styles must support readable multi-row diagnostics on mobile');
    }

    public function test_sell_cart_renders_read_only_packed_conversion_breakdown(): void
    {
        $setting = $this->createSetting('PACKED BREAKDOWN UI');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'PACKED BREAKDOWN UI CASHIER');

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell'));

        $response->assertOk();

        $html = $response->getContent();
        $this->assertStringContainsString('Rincian Kemasan:', $html);
        $this->assertStringContainsString('conversion_unit_label', $html);
        $this->assertStringContainsString('base_unit_label', $html);
        $this->assertStringContainsString('box_price_applied', $html);
        $this->assertStringContainsString('loose_price_applied', $html);
        $this->assertStringContainsString('formatMinorPrice', $html);
        $this->assertStringContainsString('Number(minorValue || 0) / 100', $html);
        $this->assertStringNotContainsString('line.breakdown.map', $html);
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
