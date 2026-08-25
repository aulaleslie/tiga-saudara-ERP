<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class HomeDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Setting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'name' => 'BUDI SANTOSO',
        ]);

        $this->setting = Setting::create([
            'company_name' => 'Test Business',
            'company_email' => 'test@example.com',
            'company_phone' => '08123456789',
            'company_address' => 'Test Address',
            'notification_email' => 'test@example.com',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'footer_text' => 'Test Footer',
            'pos_enabled' => true,
        ]);

        session(['settings' => $this->setting, 'setting_id' => $this->setting->id]);
    }

    public function test_home_is_authenticated_landing_and_renders_greeting_and_quick_access(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 26, 8, 0, 0)); // Morning

        $response = $this->actingAs($this->user)->get(route('home'));

        $response->assertStatus(200);
        $response->assertViewIs('home');
        $response->assertSee('Selamat pagi, BUDI');
        $response->assertDontSee('Pendapatan');
        $response->assertDontSee('salesPurchasesChart');
    }

    public function test_dashboard_renders_reporting_cards_and_charts_for_authorized_users(): void
    {
        Permission::create(['name' => 'reports.access']);
        $this->user->givePermissionTo('reports.access');

        $response = $this->actingAs($this->user)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertViewIs('dashboard');
        $response->assertSee('Pendapatan');
        $response->assertSee('salesPurchasesChart');
        $response->assertSee('currentMonthChart');
        $response->assertSee('paymentChart');
    }

    public function test_dashboard_hides_reporting_cards_and_charts_without_report_permission(): void
    {
        $response = $this->actingAs($this->user)->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertViewIs('dashboard');
        $response->assertDontSee('Pendapatan');
        $response->assertDontSee('salesPurchasesChart');
    }

    public function test_sidebar_contains_correct_home_and_dashboard_links_and_active_states(): void
    {
        $homeRoute = route('home');
        $dashboardRoute = route('dashboard');

        $homeResponse = $this->actingAs($this->user)->get($homeRoute);
        $homeResponse->assertSee($homeRoute);
        $homeResponse->assertSee($dashboardRoute);
        $homeResponse->assertSee('Beranda');
        $homeResponse->assertSee('Dashboard');
        $homeResponse->assertSeeInOrder([
            '<li class="c-sidebar-nav-item c-active">',
            $homeRoute,
            '<li class="c-sidebar-nav-item ">',
            $dashboardRoute,
        ], false);

        $dashboardResponse = $this->actingAs($this->user)->get($dashboardRoute);
        $dashboardResponse->assertSee($homeRoute);
        $dashboardResponse->assertSee($dashboardRoute);
        $dashboardResponse->assertSeeInOrder([
            '<li class="c-sidebar-nav-item ">',
            $homeRoute,
            '<li class="c-sidebar-nav-item c-active">',
            $dashboardRoute,
        ], false);
    }

    /**
     * @dataProvider greetingTimeProvider
     */
    public function test_greeting_periods_and_boundary_transitions(int $hour, string $expectedGreeting): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 26, $hour, 0, 0));

        $response = $this->actingAs($this->user)->get(route('home'));

        $response->assertStatus(200);
        $response->assertSee("{$expectedGreeting}, BUDI");
    }

    public static function greetingTimeProvider(): array
    {
        return [
            'morning start 04:00' => [4, 'Selamat pagi'],
            'morning end 10:00'   => [10, 'Selamat pagi'],
            'midday start 11:00'  => [11, 'Selamat siang'],
            'midday end 14:00'    => [14, 'Selamat siang'],
            'afternoon start 15:00' => [15, 'Selamat sore'],
            'afternoon end 17:00'   => [17, 'Selamat sore'],
            'night start 18:00'     => [18, 'Selamat malam'],
            'night late 23:00'      => [23, 'Selamat malam'],
            'night early 03:00'     => [3, 'Selamat malam'],
        ];
    }

    public function test_quick_access_visibility_based_on_permissions_and_pos_setting(): void
    {
        // Case 1: No permissions -> Empty state
        $response = $this->actingAs($this->user)->get(route('home'));
        $response->assertSee('Tidak ada tindakan akses cepat yang tersedia.');
        $response->assertDontSee('Buat Pembelian');
        $response->assertDontSee('Buat Penjualan');
        $response->assertDontSee('Buka Sesi POS');
        $response->assertDontSee('Buat Pembayaran Pembelian Global');
        $response->assertDontSee('Buat Pembayaran Penjualan Global');

        // Case 2: Grant purchases.create & sales.create
        Permission::create(['name' => 'purchases.create']);
        Permission::create(['name' => 'sales.create']);
        $this->user->givePermissionTo(['purchases.create', 'sales.create']);

        $response = $this->actingAs($this->user)->get(route('home'));
        $response->assertDontSee('Tidak ada tindakan akses cepat yang tersedia.');
        $response->assertSee('Buat Pembelian');
        $response->assertSee(route('purchases.create'));
        $response->assertSee('Buat Penjualan');
        $response->assertSee(route('sales.create'));
        $response->assertDontSee('Buka Sesi POS');

        // Case 3: POS session opening requires pos_enabled + pos.access + pos.sessions.open
        Permission::create(['name' => 'pos.access']);
        Permission::create(['name' => 'pos.sessions.open']);

        // Missing pos.sessions.open (has pos.access only)
        $this->user->givePermissionTo('pos.access');
        $response = $this->actingAs($this->user)->get(route('home'));
        $response->assertDontSee('Buka Sesi POS');

        // Missing pos.access (has pos.sessions.open only)
        $this->user->revokePermissionTo('pos.access');
        $this->user->givePermissionTo('pos.sessions.open');
        $response = $this->actingAs($this->user)->get(route('home'));
        $response->assertDontSee('Buka Sesi POS');

        // Both pos.access + pos.sessions.open -> visible when pos_enabled = true
        $this->user->givePermissionTo('pos.access');
        $response = $this->actingAs($this->user)->get(route('home'));
        $response->assertSee('Buka Sesi POS');
        $response->assertSee(route('pos.sessions.create'));

        // Disable POS in setting -> hidden even with permissions
        $this->setting->update(['pos_enabled' => false]);
        cache()->forget('settings_' . $this->setting->id);
        $freshSetting = $this->setting->fresh();
        session(['settings' => $freshSetting, 'setting_id' => $freshSetting->id]);
        $response = $this->actingAs($this->user)->get(route('home'));
        $response->assertDontSee('Buka Sesi POS');

        // Restore pos_enabled = true for subsequent tests
        $this->setting->update(['pos_enabled' => true]);
        cache()->forget('settings_' . $this->setting->id);
        $freshSetting = $this->setting->fresh();
        session(['settings' => $freshSetting, 'setting_id' => $freshSetting->id]);

        // Case 4: Global payment links require both global.access and create permissions
        Permission::create(['name' => 'purchasePayments.global.access']);
        Permission::create(['name' => 'purchasePayments.create']);
        Permission::create(['name' => 'salePayments.global.access']);
        Permission::create(['name' => 'salePayments.create']);

        // Grant only global.access without create -> hidden
        $this->user->givePermissionTo(['purchasePayments.global.access', 'salePayments.global.access']);
        $response = $this->actingAs($this->user)->get(route('home'));
        $response->assertDontSee('Buat Pembayaran Pembelian Global');
        $response->assertDontSee('Buat Pembayaran Penjualan Global');

        // Grant only create without global.access -> hidden
        $this->user->revokePermissionTo(['purchasePayments.global.access', 'salePayments.global.access']);
        $this->user->givePermissionTo(['purchasePayments.create', 'salePayments.create']);
        $response = $this->actingAs($this->user)->get(route('home'));
        $response->assertDontSee('Buat Pembayaran Pembelian Global');
        $response->assertDontSee('Buat Pembayaran Penjualan Global');

        // Grant both global.access and create -> visible
        $this->user->givePermissionTo(['purchasePayments.global.access', 'salePayments.global.access']);
        $response = $this->actingAs($this->user)->get(route('home'));
        $response->assertSee('Buat Pembayaran Pembelian Global');
        $response->assertSee(route('purchases.global-payments.index'));
        $response->assertSee('Buat Pembayaran Penjualan Global');
        $response->assertSee(route('sales.global-payments.index'));
    }
}
