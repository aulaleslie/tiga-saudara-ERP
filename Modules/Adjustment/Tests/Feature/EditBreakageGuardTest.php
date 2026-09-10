<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\AdjustedProduct;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EditBreakageGuardTest extends TestCase
{
    use RefreshDatabase;

    private function makeSetting(): Setting
    {
        $currency = Currency::create([
            'currency_name' => 'Rupiah', 'code' => 'IDR', 'symbol' => 'RP',
            'thousand_separator' => '.', 'decimal_separator' => ',', 'exchange_rate' => 1,
        ]);

        return Setting::create([
            'company_name' => 'CV Tiga Computer ' . uniqid(),
            'company_email' => 'ops@tiga.test',
            'company_phone' => '0800000000',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'ops@tiga.test',
            'footer_text' => 'Footer',
            'company_address' => 'Bandung',
        ]);
    }

    private function makeEditor(Setting $setting): User
    {
        $user = User::factory()->create(['is_active' => 1]);
        $permission = Permission::firstOrCreate(['name' => 'adjustments.breakage.edit', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'breakage-editor-' . $setting->id, 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    private function makeBreakage(Setting $setting, Location $location, string $status = 'pending'): Adjustment
    {
        $product = Product::create([
            'setting_id' => $setting->id, 'product_name' => 'Kabel', 'product_code' => 'SKU-' . uniqid(),
            'product_quantity' => 10, 'serial_number_required' => false,
            'product_cost' => 1000, 'product_price' => 1500, 'product_stock_alert' => 1, 'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'quantity' => 10, 'quantity_tax' => 10, 'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
        ]);

        $adjustment = Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => $status, 'location_id' => $location->id,
        ]);

        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id, 'product_id' => $product->id,
            'quantity' => 1, 'quantity_tax' => 1, 'quantity_non_tax' => 0,
            'serial_numbers' => json_encode([]), 'type' => 'sub',
        ]);

        return $adjustment;
    }

    /** @test */
    public function edit_form_rejects_a_document_owned_by_another_setting(): void
    {
        $ownSetting = $this->makeSetting();
        $otherSetting = $this->makeSetting();
        $otherLocation = Location::create(['setting_id' => $otherSetting->id, 'name' => 'Gudang Lain']);

        $user = $this->makeEditor($ownSetting);
        $adjustment = $this->makeBreakage($otherSetting, $otherLocation);

        $response = $this
            ->actingAs($user)
            ->withSession(['setting_id' => $ownSetting->id])
            ->get(route('adjustments.editBreakage', $adjustment));

        $response->assertForbidden();
    }

    /** @test */
    public function edit_form_rejects_an_already_approved_document(): void
    {
        $setting = $this->makeSetting();
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $user = $this->makeEditor($setting);
        $adjustment = $this->makeBreakage($setting, $location, 'approved');

        $response = $this
            ->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('adjustments.editBreakage', $adjustment));

        $response->assertForbidden();
    }

    /** @test */
    public function update_rejects_an_already_approved_document(): void
    {
        $setting = $this->makeSetting();
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $user = $this->makeEditor($setting);
        $adjustment = $this->makeBreakage($setting, $location, 'approved');

        $response = $this
            ->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->patch(route('adjustments.updateBreakage', $adjustment), [
                'date' => now()->format('Y-m-d'),
                'location_id' => $location->id,
                'product_ids' => [$adjustment->adjustedProducts->first()->product_id],
                'quantities_tax' => [1],
                'quantities_non_tax' => [0],
            ]);

        $response->assertForbidden();
    }

    /** @test */
    public function store_rejects_the_same_product_submitted_twice(): void
    {
        $setting = $this->makeSetting();
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $user = User::factory()->create(['is_active' => 1]);
        $permission = Permission::firstOrCreate(['name' => 'adjustments.breakage.create', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'breakage-creator-' . $setting->id, 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        $product = Product::create([
            'setting_id' => $setting->id, 'product_name' => 'Kabel', 'product_code' => 'SKU-DUPROW',
            'product_quantity' => 10, 'serial_number_required' => false,
            'product_cost' => 1000, 'product_price' => 1500, 'product_stock_alert' => 1, 'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'quantity' => 10, 'quantity_tax' => 10, 'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
        ]);

        $response = $this
            ->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('adjustments.storeBreakage'), [
                'reference' => 'BRK',
                'date' => now()->format('Y-m-d'),
                'location_id' => $location->id,
                'product_ids' => [$product->id, $product->id],
                'quantities_tax' => [1, 1],
                'quantities_non_tax' => [0, 0],
            ]);

        $response->assertSessionHasErrors('product_ids');
        $this->assertDatabaseCount('adjustments', 0);
    }

    /** @test */
    public function edit_form_rejects_a_legacy_normal_adjustment_with_pending_status(): void
    {
        $setting = $this->makeSetting();
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
        $user = $this->makeEditor($setting);

        $product = Product::create([
            'setting_id' => $setting->id, 'product_name' => 'Kabel', 'product_code' => 'SKU-NORMAL',
            'product_quantity' => 10, 'serial_number_required' => false,
            'product_cost' => 1000, 'product_price' => 1500, 'product_stock_alert' => 1, 'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'quantity' => 10, 'quantity_tax' => 10, 'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
        ]);

        // A legacy 'normal' adjustment carrying the same (legacy) pending
        // status as breakage must never pass through the breakage edit
        // routes just because status alone matches.
        $adjustment = Adjustment::create([
            'date' => now(), 'type' => 'normal', 'status' => 'pending', 'location_id' => $location->id,
        ]);

        AdjustedProduct::create([
            'adjustment_id' => $adjustment->id, 'product_id' => $product->id,
            'quantity' => 1, 'quantity_tax' => 1, 'quantity_non_tax' => 0,
            'serial_numbers' => json_encode([]), 'type' => 'sub',
        ]);

        $response = $this
            ->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('adjustments.editBreakage', $adjustment));

        $response->assertForbidden();
    }
}
