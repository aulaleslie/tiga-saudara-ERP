<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

class POSSuperAdminBypassTest extends TestCase
{
    use RefreshDatabase;

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

        Role::findOrCreate('Super Admin', 'web');
    }

    public function test_super_admin_can_close_session_without_setting_assignment(): void
    {
        [$setting, $cashier, $session] = $this->createOpenSession();

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('Super Admin');

        // Verify Super Admin is NOT assigned to the setting
        $this->assertDatabaseMissing('user_setting', [
            'user_id' => $superAdmin->id,
            'setting_id' => $setting->id,
        ]);

        // Attempt to close session as Super Admin (who is NOT the cashier)
        // Note: PosSessionCloseService currently throws DomainException for setting check
        // AND AuthorizationException if not the cashier. We need to bypass BOTH for Super Admin.
        
        $response = $this->actingAs($superAdmin)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sessions.close.finalize', ['session' => $session->id]), []);

        $response->assertOk();
        
        $this->assertDatabaseHas('pos_sessions', [
            'id' => $session->id,
            'status' => 'CLOSED',
            'closed_by' => $superAdmin->id,
        ]);
    }

    public function test_super_admin_can_perform_safe_drop_without_setting_assignment(): void
    {
        [$setting, $cashier, $session] = $this->createOpenSession();

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('Super Admin');

        $response = $this->actingAs($superAdmin)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sessions.safe-drops.store', ['session' => $session->id]), [
                'amount' => 1000,
                'reason' => 'Mid-day pickup',
            ]);

        $response->assertOk();
        
        $this->assertDatabaseHas('pos_session_cash_events', [
            'pos_session_id' => $session->id,
            'amount' => 1000,
            'performed_by' => $superAdmin->id,
            'event_type' => \Modules\Pos\Entities\PosSessionCashEvent::EVENT_SAFE_DROP_OUT,
        ]);
    }

    public function test_super_admin_can_finalize_session_without_setting_assignment(): void
    {
        [$setting, $cashier, $session] = $this->createOpenSession();

        // Close the session first so it can be finalized
        $session->update(['status' => 'CLOSED', 'closed_at' => now(), 'closed_by' => $cashier->id]);

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('Super Admin');

        $response = $this->actingAs($superAdmin)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sessions.finalize', ['session' => $session->id]), [
                'actual_cash_received' => 105000,
                'reason' => 'Daily finalization',
            ]);

        $response->assertOk();
        
        $this->assertDatabaseHas('pos_sessions', [
            'id' => $session->id,
            'status' => 'FINALIZED',
        ]);
    }

    public function test_non_super_admin_is_blocked_without_setting_assignment(): void
    {
        [$setting, $cashier, $session] = $this->createOpenSession();

        $otherUser = User::factory()->create();
        // Give permissions but NO setting assignment
        $role = Role::create(['name' => 'Other Cashier']);
        $role->syncPermissions(['pos.access', 'pos.sell', 'pos.sessions.close']);
        $otherUser->assignRole($role);

        $response = $this->actingAs($otherUser)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sessions.close.finalize', ['session' => $session->id]), []);

        // Should fail with 403 because CheckUserRoleForSetting middleware clears roles if not assigned to setting
        $response->assertStatus(403);
    }

    private function createOpenSession(): array
    {
        $setting = Setting::create([
            'company_name' => 'Bypass Test Biz',
            'company_email' => 'bypass@example.com',
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

        $cashierRole = Role::findOrCreate('Cashier-' . $setting->id);
        $cashierRole->syncPermissions(['pos.access', 'pos.sell', 'pos.sessions.open', 'pos.sessions.close']);

        $cashier = User::factory()->create();
        $cashier->assignRole($cashierRole);
        $cashier->settings()->attach($setting->id, ['role_id' => $cashierRole->id]);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'T-01',
            'name' => 'Test Terminal',
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => 5000,
            'cash_threshold' => 50000,
            'require_pickup_supervisor_approval' => true,
        ]);

        $coaId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'Cash',
            'account_number' => '1001',
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

        \Modules\Setting\Entities\SettingPosPaymentMethod::create([
            'setting_id' => $setting->id,
            'payment_method_id' => $method->id,
            'is_enabled' => true,
        ]);

        $location = Location::create([
            'name' => 'Test Location',
            'setting_id' => $setting->id,
        ]);

        \Modules\Setting\Entities\SettingSaleLocation::create([
            'setting_id' => $setting->id,
            'location_id' => $location->id,
            'is_enabled' => true,
        ]);

        $session = app(PosSessionLifecycleService::class)->openSession(
            $setting->id,
            $terminal->id,
            $cashier->id,
            100000,
            ['100000' => 1],
            $cashier->id
        );

        return [$setting, $cashier, $session];
    }
}
