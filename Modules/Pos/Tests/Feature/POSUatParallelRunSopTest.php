<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
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
class POSUatParallelRunSopTest extends TestCase
{
    use RefreshDatabase;

    private string $uatScriptPath;
    private string $sopPath;

    protected function setUp(): void
    {
        parent::setUp();

        // The requirement is to have these docs in the specified path
        $this->uatScriptPath = base_path('docs/pos/pos-mvp-uat-script.md');
        $this->sopPath = base_path('docs/pos/pos-mvp-parallel-run-sop.md');

        Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::findOrCreate('sales.access', 'web');
        Permission::findOrCreate('pos.access', 'web');
        Permission::findOrCreate('pos.sell', 'web');
    }

    public function test_uat_script_document_exists_and_covers_scenarios(): void
    {
        $this->assertTrue(File::exists($this->uatScriptPath), 'UAT script document is missing.');

        $content = File::get($this->uatScriptPath);

        // Core workflow scenarios
        $this->assertStringContainsString('POS-UAT-001', $content);
        $this->assertStringContainsString('POS-UAT-002', $content);
        $this->assertStringContainsString('POS-UAT-003', $content);

        // Hardware scenarios
        $this->assertStringContainsString('POS-UAT-010', $content);
        $this->assertStringContainsString('POS-UAT-011', $content);
        $this->assertStringContainsString('POS-UAT-012', $content);

        // Fallback/Parallel scenarios
        $this->assertStringContainsString('POS-UAT-020', $content);
        $this->assertStringContainsString('POS-UAT-021', $content);
        $this->assertStringContainsString('POS-UAT-022', $content);

        // Required execution verification references
        $this->assertStringContainsString('php artisan test --testsuite=Pos --group=pos-critical-path', $content);
    }

    public function test_parallel_run_sop_document_exists_and_covers_rules(): void
    {
        $this->assertTrue(File::exists($this->sopPath), 'Parallel Run SOP document is missing.');

        $content = File::get($this->sopPath);

        // Core rules that must be communicated to the team
        $this->assertMatchesRegularExpression('/MUST NOT( be)? re-enter(ed)?/i', $content, 'SOP is missing strict duplicate prevention guidance.');
        $this->assertStringContainsStringIgnoringCase('Fallback', $content);
        $this->assertStringContainsStringIgnoringCase('Rollback', $content);
        $this->assertStringContainsStringIgnoringCase('Duplicate', $content);
    }

    public function test_fallback_sop_mechanics_pos_can_be_disabled_to_block_access_without_losing_sales_access(): void
    {
        $cashierRole = Role::firstOrCreate(['name' => 'Cashier']);
        $user = User::factory()->create();
        $user->assignRole($cashierRole);
        $user->givePermissionTo(['sales.access', 'pos.access', 'pos.sell']);

        $setting = Setting::create([
            'company_name' => 'POS Rollback Test',
            'company_email' => 'rollback@example.com',
            'company_phone' => '0800',
            'company_address' => 'Address',
            'default_currency_id' => Currency::query()->value('id'),
            'default_currency_position' => 'prefix',
            'pos_enabled' => true,
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => 'DOC',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
        ]);

        $user->settings()->attach($setting->id, ['role_id' => $cashierRole->id]);

        $location = Location::create([
            'name' => 'Location',
            'setting_id' => $setting->id,
        ]);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'TERM-1',
            'name' => 'Terminal 1',
            'location_id' => $location->id,
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
        ]);

        PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $user->id,
            'status' => PosSession::STATUS_OPEN,
            'opened_at' => now(),
            'opened_by' => $user->id,
            'active_marker' => 1,
        ]);

        // Scenario 1: POS is enabled (Parallel Run active)
        $response = $this->actingAs($user)->withSession(['setting_id' => $setting->id])->get(route('pos.sell'));
        $response->assertOk();
        $response->assertSee('POS Sell Screen');

        // Scenario 2: Rollback executed (POS feature flag toggled off)
        $setting->update(['pos_enabled' => false]);

        // Scenario 3: Cashier attempts to continue using POS
        $responseDisabled = $this->actingAs($user)->withSession(['setting_id' => $setting->id])->get(route('pos.sell'));
        
        // Assert cashier is smoothly redirected to legacy sales with a message
        $responseDisabled->assertRedirect(route('sales.index'));
        $responseDisabled->assertSessionHas('warning', 'POS is disabled for the current business.');
        
        // Assert legacy sales is still accessible (SOP requirement)
        $this->actingAs($user)->withSession(['setting_id' => $setting->id])->get(route('sales.index'))->assertOk();
    }
}
