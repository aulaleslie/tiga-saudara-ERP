<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task 5.2: Focused request/service tests for breakage store/update --
 * single-bucket PKP/Non-PKP persistence, excessive-quantity rejection,
 * cross-setting/consignment rejection, and no inventory mutation before
 * approval (design.md "Use one displayed breakage quantity and derive its
 * storage bucket" / "Treat the selected location as the authoritative
 * context").
 */
class StoreBreakageValidationTest extends TestCase
{
    use RefreshDatabase;

    private function makeSetting(bool $isPkp): Setting
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
            'is_pkp' => $isPkp,
        ]);
    }

    private function makeCreator(Setting $setting): User
    {
        $user = User::factory()->create(['is_active' => 1]);
        $permission = Permission::firstOrCreate(['name' => 'adjustments.breakage.create', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'breakage-creator-' . uniqid(), 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        return $user;
    }

    private function makeProduct(Setting $setting, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'setting_id' => $setting->id,
            'product_name' => 'Kabel',
            'product_code' => 'SKU-' . uniqid(),
            'product_quantity' => 10,
            'serial_number_required' => false,
            'product_cost' => 1000,
            'product_price' => 1500,
            'product_stock_alert' => 1,
            'stock_managed' => true,
        ], $overrides));
    }

    /** @test */
    public function it_persists_quantity_in_the_tax_bucket_for_a_pkp_location(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang PKP']);
        $product = $this->makeProduct($setting);

        ProductStock::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'quantity' => 10, 'quantity_tax' => 10, 'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
        ]);

        $user = $this->makeCreator($setting);

        $response = $this
            ->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('adjustments.storeBreakage'), [
                'reference' => 'BRK',
                'date' => now()->format('Y-m-d'),
                'location_id' => $location->id,
                'product_ids' => [$product->id],
                'quantities_tax' => [4],
                'quantities_non_tax' => [0],
            ]);

        $response->assertRedirect(route('adjustments.index'));

        $adjustment = Adjustment::firstOrFail();
        $this->assertSame(4, (int) $adjustment->adjustedProducts->first()->quantity_tax);
        $this->assertSame(0, (int) $adjustment->adjustedProducts->first()->quantity_non_tax);

        // No inventory mutation before approval.
        $stock = ProductStock::where('product_id', $product->id)->where('location_id', $location->id)->first();
        $this->assertSame(10, (int) $stock->quantity_tax);
        $this->assertSame(0, (int) $stock->broken_quantity_tax);
    }

    /** @test */
    public function it_persists_quantity_in_the_non_tax_bucket_for_a_non_pkp_location(): void
    {
        $setting = $this->makeSetting(false);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang Non-PKP']);
        $product = $this->makeProduct($setting);

        ProductStock::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'quantity' => 10, 'quantity_tax' => 0, 'quantity_non_tax' => 10,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
        ]);

        $user = $this->makeCreator($setting);

        $response = $this
            ->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('adjustments.storeBreakage'), [
                'reference' => 'BRK',
                'date' => now()->format('Y-m-d'),
                'location_id' => $location->id,
                'product_ids' => [$product->id],
                'quantities_tax' => [0],
                'quantities_non_tax' => [3],
            ]);

        $response->assertRedirect(route('adjustments.index'));

        $adjustment = Adjustment::firstOrFail();
        $this->assertSame(0, (int) $adjustment->adjustedProducts->first()->quantity_tax);
        $this->assertSame(3, (int) $adjustment->adjustedProducts->first()->quantity_non_tax);
    }

    /** @test */
    public function it_rejects_quantity_submitted_in_the_wrong_tax_bucket_for_the_location(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang PKP']);
        $product = $this->makeProduct($setting);

        ProductStock::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'quantity' => 10, 'quantity_tax' => 10, 'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
        ]);

        $user = $this->makeCreator($setting);

        $response = $this
            ->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('adjustments.storeBreakage'), [
                'reference' => 'BRK',
                'date' => now()->format('Y-m-d'),
                'location_id' => $location->id,
                'product_ids' => [$product->id],
                'quantities_tax' => [0],
                'quantities_non_tax' => [4],
            ]);

        $response->assertSessionHasErrors();
        $this->assertDatabaseCount('adjustments', 0);
    }

    /** @test */
    public function it_rejects_quantity_exceeding_available_good_stock(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang PKP']);
        $product = $this->makeProduct($setting);

        ProductStock::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'quantity' => 3, 'quantity_tax' => 3, 'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
        ]);

        $user = $this->makeCreator($setting);

        $response = $this
            ->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('adjustments.storeBreakage'), [
                'reference' => 'BRK',
                'date' => now()->format('Y-m-d'),
                'location_id' => $location->id,
                'product_ids' => [$product->id],
                'quantities_tax' => [99],
                'quantities_non_tax' => [0],
            ]);

        $response->assertSessionHasErrors();
        $this->assertDatabaseCount('adjustments', 0);

        $stock = ProductStock::where('product_id', $product->id)->where('location_id', $location->id)->first();
        $this->assertSame(3, (int) $stock->quantity_tax);
    }

    /** @test */
    public function it_rejects_zero_quantity(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang PKP']);
        $product = $this->makeProduct($setting);

        ProductStock::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'quantity' => 10, 'quantity_tax' => 10, 'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
        ]);

        $user = $this->makeCreator($setting);

        $response = $this
            ->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('adjustments.storeBreakage'), [
                'reference' => 'BRK',
                'date' => now()->format('Y-m-d'),
                'location_id' => $location->id,
                'product_ids' => [$product->id],
                'quantities_tax' => [0],
                'quantities_non_tax' => [0],
            ]);

        $response->assertSessionHasErrors();
        $this->assertDatabaseCount('adjustments', 0);
    }

    /** @test */
    public function it_rejects_a_location_from_a_different_setting(): void
    {
        $setting = $this->makeSetting(true);
        $otherSetting = $this->makeSetting(true);
        $otherLocation = Location::create(['setting_id' => $otherSetting->id, 'name' => 'Gudang Lain']);
        $product = $this->makeProduct($setting);

        $user = $this->makeCreator($setting);

        $response = $this
            ->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('adjustments.storeBreakage'), [
                'reference' => 'BRK',
                'date' => now()->format('Y-m-d'),
                'location_id' => $otherLocation->id,
                'product_ids' => [$product->id],
                'quantities_tax' => [1],
                'quantities_non_tax' => [0],
            ]);

        $response->assertSessionHasErrors('location_id');
        $this->assertDatabaseCount('adjustments', 0);
    }

    /** @test */
    public function it_rejects_a_consignment_location(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create([
            'setting_id' => $setting->id, 'name' => 'Gudang Konsinyasi', 'is_consignment' => true,
        ]);
        $product = $this->makeProduct($setting);

        ProductStock::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'quantity' => 10, 'quantity_tax' => 10, 'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
        ]);

        $user = $this->makeCreator($setting);

        $response = $this
            ->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('adjustments.storeBreakage'), [
                'reference' => 'BRK',
                'date' => now()->format('Y-m-d'),
                'location_id' => $location->id,
                'product_ids' => [$product->id],
                'quantities_tax' => [1],
                'quantities_non_tax' => [0],
            ]);

        $response->assertSessionHasErrors('location_id');
        $this->assertDatabaseCount('adjustments', 0);
    }

    /** @test */
    public function successful_store_does_not_mutate_stock_until_approval(): void
    {
        $setting = $this->makeSetting(true);
        $location = Location::create(['setting_id' => $setting->id, 'name' => 'Gudang PKP']);
        $product = $this->makeProduct($setting);

        ProductStock::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'quantity' => 10, 'quantity_tax' => 10, 'quantity_non_tax' => 0,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 0, 'broken_quantity' => 0,
        ]);

        $user = $this->makeCreator($setting);

        $this->actingAs($user)
            ->withSession(['setting_id' => $setting->id])
            ->post(route('adjustments.storeBreakage'), [
                'reference' => 'BRK',
                'date' => now()->format('Y-m-d'),
                'location_id' => $location->id,
                'product_ids' => [$product->id],
                'quantities_tax' => [5],
                'quantities_non_tax' => [0],
            ]);

        $adjustment = Adjustment::firstOrFail();
        $this->assertSame('PENDING', \Modules\Adjustment\Entities\AdjustmentStatus::normalize($adjustment->status)->value);

        $stock = ProductStock::where('product_id', $product->id)->where('location_id', $location->id)->first();
        $this->assertSame(10, (int) $stock->quantity_tax);
        $this->assertSame(0, (int) $stock->broken_quantity_tax);
    }
}
