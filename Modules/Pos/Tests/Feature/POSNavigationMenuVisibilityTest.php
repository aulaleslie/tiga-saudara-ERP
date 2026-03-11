<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * @group pos-critical-path
 */
class POSNavigationMenuVisibilityTest extends TestCase
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
            'pos.sessions.view',
            'pos.transactions.view',
            'sales.access',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_home_shows_pos_navigation_when_user_has_pos_permissions(): void
    {
        $setting = $this->createSetting('BIZ POS NAV VISIBLE', true);
        $user = $this->createUserForSetting($setting, 'POS NAV VISIBLE ROLE', [
            'pos.access',
            'pos.sell',
            'pos.sessions.view',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('home'));

        $response->assertOk();
        $response->assertSee('POS Kasir');
        $response->assertSee('Sesi POS');
        $response->assertSee('Buka POS');
    }

    public function test_home_hides_pos_navigation_when_user_has_no_pos_permissions(): void
    {
        $setting = $this->createSetting('BIZ POS NAV HIDDEN', true);
        $user = $this->createUserForSetting($setting, 'POS NAV HIDDEN ROLE', ['sales.access']);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('home'));

        $response->assertOk();
        $response->assertDontSee('POS Kasir');
        $response->assertDontSee('Sesi POS');
        $response->assertDontSee('Buka POS');
    }

    public function test_home_shows_transaction_menu_when_transactions_feature_enabled(): void
    {
        $setting = $this->createSetting('BIZ POS NAV TXN ON', true, true);
        $user = $this->createUserForSetting($setting, 'POS NAV TXN ON ROLE', [
            'pos.access',
            'pos.transactions.view',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('home'));

        $response->assertOk();
        $response->assertSee('Transaksi POS');
    }

    public function test_home_hides_transaction_menu_when_transactions_feature_disabled(): void
    {
        $setting = $this->createSetting('BIZ POS NAV TXN OFF', true, false);
        $user = $this->createUserForSetting($setting, 'POS NAV TXN OFF ROLE', [
            'pos.access',
            'pos.transactions.view',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('home'));

        $response->assertOk();
        $response->assertDontSee('Transaksi POS');
    }

    private function createSetting(string $name, bool $posEnabled, bool $posTransactionsEnabled = false): Setting
    {
        $setting = Setting::create([
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
            'pos_enabled' => $posEnabled,
            'pos_transactions_enabled' => $posTransactionsEnabled,
        ]);

        cache()->forget('settings_' . $setting->id);

        return $setting;
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
}
