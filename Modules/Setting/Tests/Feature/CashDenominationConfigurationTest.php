<?php

declare(strict_types=1);

namespace Modules\Setting\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\SettingCashDenomination;
use Modules\Setting\Enums\CashDenomination;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CashDenominationConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Setting $setting;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('cashDenominations.access', 'web');
        Permission::findOrCreate('cashDenominations.edit', 'web');

        $this->user = User::factory()->create();
        $this->user->givePermissionTo([
            'cashDenominations.access',
            'cashDenominations.edit',
        ]);

        $this->setting = Setting::factory()->create();

        $this->actingAs($this->user)->withSession([
            'setting_id' => $this->setting->id,
        ]);
    }

    public function test_new_setting_receives_all_supported_denominations_enabled_by_default(): void
    {
        $configurations = SettingCashDenomination::query()
            ->where('setting_id', $this->setting->id)
            ->orderBy('position')
            ->get();

        $this->assertCount(11, $configurations);
        $this->assertSame(
            CashDenomination::values(),
            $configurations->pluck('denomination')
                ->map(static fn (CashDenomination $denomination): int => $denomination->value)
                ->all(),
        );
        $this->assertTrue($configurations->every('is_enabled'));
    }

    public function test_index_displays_coin_and_banknote_denominations(): void
    {
        $response = $this->get(route('cash-denomination-configurations.index'));

        $response->assertOk();
        $response->assertSee('Konfigurasi Pecahan Uang');
        $response->assertSee('Uang Logam');
        $response->assertSee('Uang Kertas');
        $response->assertSee('Rp100');
        $response->assertSee('Rp500');
        $response->assertSee('Rp1.000');
        $response->assertSee('Rp20.000');
        $response->assertSee('Rp75.000');
        $response->assertSee('Rp100.000');
        $response->assertSee('Satuan: keping');
        $response->assertSee('Satuan: lembar');
    }

    public function test_it_updates_enabled_denominations_for_active_setting(): void
    {
        $enabled = [100, 1_000, 20_000, 100_000];

        $response = $this->put(route('cash-denomination-configurations.update'), [
            'enabled_denominations' => $enabled,
        ]);

        $response->assertRedirect(route('cash-denomination-configurations.index'));

        $this->assertEqualsCanonicalizing(
            $enabled,
            SettingCashDenomination::query()
                ->where('setting_id', $this->setting->id)
                ->where('is_enabled', true)
                ->get()
                ->map(static fn (SettingCashDenomination $configuration): int => $configuration->denomination->value)
                ->all(),
        );
        $this->assertSame(
            7,
            SettingCashDenomination::query()
                ->where('setting_id', $this->setting->id)
                ->where('is_enabled', false)
                ->count(),
        );
    }

    public function test_it_can_disable_all_denominations(): void
    {
        $response = $this->put(route('cash-denomination-configurations.update'));

        $response->assertRedirect(route('cash-denomination-configurations.index'));
        $this->assertSame(
            0,
            SettingCashDenomination::query()
                ->where('setting_id', $this->setting->id)
                ->where('is_enabled', true)
                ->count(),
        );
    }

    public function test_configuration_is_isolated_between_settings(): void
    {
        $otherSetting = Setting::factory()->create();

        $this->put(route('cash-denomination-configurations.update'), [
            'enabled_denominations' => [100, 200],
        ])->assertRedirect(route('cash-denomination-configurations.index'));

        $this->assertSame(
            2,
            SettingCashDenomination::query()
                ->where('setting_id', $this->setting->id)
                ->where('is_enabled', true)
                ->count(),
        );
        $this->assertSame(
            11,
            SettingCashDenomination::query()
                ->where('setting_id', $otherSetting->id)
                ->where('is_enabled', true)
                ->count(),
        );
    }

    public function test_it_rejects_unsupported_or_duplicate_denominations(): void
    {
        $this->from(route('cash-denomination-configurations.index'))
            ->put(route('cash-denomination-configurations.update'), [
                'enabled_denominations' => [100, 100, 250],
            ])
            ->assertRedirect(route('cash-denomination-configurations.index'))
            ->assertSessionHasErrors('enabled_denominations.1')
            ->assertSessionHasErrors('enabled_denominations.2');

        $this->assertSame(
            11,
            SettingCashDenomination::query()
                ->where('setting_id', $this->setting->id)
                ->where('is_enabled', true)
                ->count(),
        );
    }

    public function test_user_with_access_permission_sees_read_only_configuration(): void
    {
        $this->user->revokePermissionTo('cashDenominations.edit');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $response = $this->get(route('cash-denomination-configurations.index'));

        $response->assertOk();
        $response->assertSee('Konfigurasi Pecahan Uang');
        $response->assertDontSee('Simpan Konfigurasi');
        $response->assertSee('tidak memiliki izin untuk mengubah konfigurasi');
    }

    public function test_user_without_access_permission_cannot_view_configuration(): void
    {
        $this->user->revokePermissionTo('cashDenominations.access');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->get(route('cash-denomination-configurations.index'))
            ->assertForbidden();
    }

    public function test_user_without_edit_permission_cannot_update_configuration(): void
    {
        $this->user->revokePermissionTo('cashDenominations.edit');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->put(route('cash-denomination-configurations.update'), [
            'enabled_denominations' => [100],
        ])->assertForbidden();

        $this->assertSame(
            11,
            SettingCashDenomination::query()
                ->where('setting_id', $this->setting->id)
                ->where('is_enabled', true)
                ->count(),
        );
    }

    public function test_one_thousand_is_a_banknote_and_twenty_thousand_is_supported(): void
    {
        $this->assertSame('banknote', CashDenomination::Banknote1000->type());
        $this->assertContains(20_000, CashDenomination::values());
    }
}
