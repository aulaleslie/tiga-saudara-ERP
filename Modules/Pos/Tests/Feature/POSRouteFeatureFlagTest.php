<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class POSRouteFeatureFlagTest extends TestCase
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

        Permission::findOrCreate('sales.access', 'web');
        Permission::findOrCreate('pos.access', 'web');
        Permission::findOrCreate('pos.sell', 'web');
    }

    public function test_it_redirects_to_sales_when_pos_is_disabled_and_user_can_access_sales(): void
    {
        [$user, $setting] = $this->createCashierForSetting(posEnabled: false, grantSalesAccess: true);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get('/pos/sell');

        $response->assertRedirect(route('sales.index'));
        $response->assertSessionHas('warning', 'POS is disabled for the current business.');
    }

    public function test_it_returns_forbidden_when_pos_is_disabled_and_user_cannot_access_sales(): void
    {
        [$user, $setting] = $this->createCashierForSetting(posEnabled: false, grantSalesAccess: false);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get('/pos/sell');

        $response->assertForbidden();
    }

    public function test_it_allows_pos_route_when_pos_is_enabled_for_current_setting(): void
    {
        [$user, $setting] = $this->createCashierForSetting(posEnabled: true, grantSalesAccess: true);

        $response = $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get('/pos/sell');

        $response->assertOk();
        $response->assertSee('POS Sell Screen');
    }

    public function test_it_applies_flag_per_setting_when_switching_context(): void
    {
        $cashierRole = Role::firstOrCreate(['name' => 'Cashier']);
        $user = User::factory()->create();
        $user->assignRole($cashierRole);

        $enabledSetting = $this->createSetting('BIZ ENABLED', true);
        $disabledSetting = $this->createSetting('BIZ DISABLED', false);

        $user->settings()->attach($enabledSetting->id, ['role_id' => $cashierRole->id]);
        $user->settings()->attach($disabledSetting->id, ['role_id' => $cashierRole->id]);
        $user->givePermissionTo('sales.access');
        $user->givePermissionTo(['pos.access', 'pos.sell']);

        $this->actingAs($user);

        $this->withSession(['setting_id' => $enabledSetting->id])
            ->get('/pos/sell')
            ->assertOk();

        $this->withSession(['setting_id' => $disabledSetting->id])
            ->get('/pos/sell')
            ->assertRedirect(route('sales.index'));
    }

    private function createCashierForSetting(bool $posEnabled, bool $grantSalesAccess): array
    {
        $cashierRole = Role::firstOrCreate(['name' => 'Cashier']);

        $user = User::factory()->create();
        $user->assignRole($cashierRole);

        if ($grantSalesAccess) {
            $user->givePermissionTo('sales.access');
        }

        $user->givePermissionTo(['pos.access', 'pos.sell']);

        $setting = $this->createSetting(
            $posEnabled ? 'POS ENABLED BUSINESS' : 'POS DISABLED BUSINESS',
            $posEnabled
        );

        $user->settings()->attach($setting->id, ['role_id' => $cashierRole->id]);

        return [$user, $setting];
    }

    private function createSetting(string $name, bool $posEnabled): Setting
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
            'pos_enabled' => $posEnabled,
        ]);
    }
}
