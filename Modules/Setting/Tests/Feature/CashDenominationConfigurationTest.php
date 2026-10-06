<?php

declare(strict_types=1);

namespace Modules\Setting\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Setting\Entities\CashDenomination;
use Modules\Setting\Entities\Setting;
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

    public function test_default_denominations_are_created_globally(): void
    {
        $denominations = CashDenomination::query()->orderBy('position')->get();

        $this->assertCount(11, $denominations);
        $this->assertSame(
            [100, 200, 500, 1_000, 2_000, 5_000, 10_000, 20_000, 50_000, 75_000, 100_000],
            $denominations->pluck('value')->all(),
        );
        $this->assertTrue($denominations->every('is_enabled'));
        $this->assertFalse(Schema::hasColumn('cash_denominations', 'setting_id'));
    }

    public function test_index_displays_coin_and_banknote_denominations(): void
    {
        $response = $this->get(route('cash-denomination-configurations.index'));

        $response->assertOk();
        $response->assertSee('Konfigurasi Pecahan Uang');
        $response->assertSee('Global');
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

    public function test_it_updates_enabled_denominations_globally(): void
    {
        $enabledIds = CashDenomination::query()
            ->whereIn('value', [100, 1_000, 20_000, 100_000])
            ->pluck('id')
            ->all();

        $response = $this->put(route('cash-denomination-configurations.update'), [
            'enabled_denomination_ids' => $enabledIds,
        ]);

        $response->assertRedirect(route('cash-denomination-configurations.index'));
        $this->assertEqualsCanonicalizing(
            [100, 1_000, 20_000, 100_000],
            CashDenomination::query()->where('is_enabled', true)->pluck('value')->all(),
        );
        $this->assertSame(7, CashDenomination::query()->where('is_enabled', false)->count());
    }

    public function test_it_can_disable_all_denominations(): void
    {
        $this->put(route('cash-denomination-configurations.update'))
            ->assertRedirect(route('cash-denomination-configurations.index'));

        $this->assertSame(0, CashDenomination::query()->where('is_enabled', true)->count());
    }

    public function test_configuration_is_shared_between_settings(): void
    {
        $enabledId = CashDenomination::query()->where('value', 100)->value('id');

        $this->put(route('cash-denomination-configurations.update'), [
            'enabled_denomination_ids' => [$enabledId],
        ])->assertRedirect(route('cash-denomination-configurations.index'));

        $otherSetting = Setting::factory()->create();
        $response = $this->withSession(['setting_id' => $otherSetting->id])
            ->get(route('cash-denomination-configurations.index'));

        $response->assertOk();
        $response->assertSee('Perubahan berlaku untuk seluruh perusahaan.');
        $this->assertSame(1, CashDenomination::query()->where('is_enabled', true)->count());
    }

    public function test_database_denominations_work_without_code_changes(): void
    {
        $denomination = CashDenomination::query()->create([
            'value' => 1,
            'type' => 'banknote',
            'is_enabled' => false,
            'position' => 12,
        ]);

        $this->get(route('cash-denomination-configurations.index'))
            ->assertOk()
            ->assertSee('Rp1');

        $this->put(route('cash-denomination-configurations.update'), [
            'enabled_denomination_ids' => [$denomination->id],
        ])->assertRedirect(route('cash-denomination-configurations.index'));

        $this->assertTrue($denomination->fresh()->is_enabled);
    }

    public function test_it_rejects_unknown_or_duplicate_denomination_ids(): void
    {
        $denominationId = CashDenomination::query()->value('id');

        $this->from(route('cash-denomination-configurations.index'))
            ->put(route('cash-denomination-configurations.update'), [
                'enabled_denomination_ids' => [$denominationId, $denominationId, 999_999],
            ])
            ->assertRedirect(route('cash-denomination-configurations.index'))
            ->assertSessionHasErrors('enabled_denomination_ids.1')
            ->assertSessionHasErrors('enabled_denomination_ids.2');

        $this->assertSame(11, CashDenomination::query()->where('is_enabled', true)->count());
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

        $denominationId = CashDenomination::query()->value('id');

        $this->put(route('cash-denomination-configurations.update'), [
            'enabled_denomination_ids' => [$denominationId],
        ])->assertForbidden();

        $this->assertSame(11, CashDenomination::query()->where('is_enabled', true)->count());
    }
}
