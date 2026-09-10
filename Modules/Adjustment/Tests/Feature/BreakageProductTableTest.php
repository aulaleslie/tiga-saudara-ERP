<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use App\Livewire\Adjustment\BreakageProductTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Tax;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Task 5.1: Focused Livewire tests for the redesigned breakage entry
 * component -- location selection/reset, ordinary and conversion scans,
 * ambiguity handling, focus-restoration events, and create/edit state
 * retention (design.md "Use a breakage-specific editor backed by shared
 * entry services").
 */
class BreakageProductTableTest extends TestCase
{
    use RefreshDatabase;

    private function makeSetting(bool $isPkp = false): Setting
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

    private function makeLocation(Setting $setting): Location
    {
        return Location::create([
            'setting_id' => $setting->id,
            'name' => 'Gudang ' . uniqid(),
            'is_consignment' => false,
        ]);
    }

    private function makeUser(Setting $setting): User
    {
        $user = User::factory()->create(['is_active' => 1]);
        $role = Role::firstOrCreate(['name' => 'staff-' . uniqid(), 'guard_name' => 'web']);
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);
        return $user;
    }

    /**
     * BreakageProductTable now revalidates every location/product lookup
     * against the active session setting, so tests must set it the same way
     * the real request lifecycle (SetActiveSetting middleware) does.
     */
    private function actingAsUser(Setting $setting): void
    {
        $this->actingAs($this->makeUser($setting));
        session(['setting_id' => $setting->id]);
    }

    private function makeStock(Product $product, Location $location, array $overrides = []): ProductStock
    {
        return ProductStock::create(array_merge([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 0,
            'quantity_tax' => 0,
            'quantity_non_tax' => 0,
            'broken_quantity' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
        ], $overrides));
    }

    private function makeProduct(Setting $setting, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'setting_id' => $setting->id,
            'product_name' => 'Kabel HDMI',
            'product_code' => 'SKU-HDMI-1',
            'barcode' => 'BC-HDMI-1',
            'product_quantity' => 10,
            'serial_number_required' => false,
            'product_cost' => 1000,
            'product_price' => 1500,
            'product_stock_alert' => 1,
            'stock_managed' => true,
        ], $overrides));
    }

    public function test_scan_requires_location_selected_first(): void
    {
        $setting = $this->makeSetting();
        $this->actingAsUser($setting);

        Livewire::test(BreakageProductTable::class)
            ->set('scanInput', 'BC-HDMI-1')
            ->call('processScan')
            ->assertSet('products', [])
            ->assertSee('Pilih lokasi terlebih dahulu sebelum memindai.')
            ->assertDispatched('select-scan-input');
    }

    public function test_ordinary_barcode_scan_increments_quantity_by_one(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $product = $this->makeProduct($setting);

        $this->makeStock($product, $location, [
            'quantity' => 5, 'quantity_tax' => 0, 'quantity_non_tax' => 5,
        ]);

        $this->actingAsUser($setting);

        Livewire::test(BreakageProductTable::class, ['locationId' => $location->id])
            ->set('scanInput', 'BC-HDMI-1')
            ->call('processScan')
            ->assertCount('products', 1)
            ->assertSet('quantities.0', 1)
            ->assertSet('scanInput', '')
            ->assertDispatched('restore-scanner-focus');
    }

    public function test_ordinary_barcode_scan_blocked_beyond_available_good_stock(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $product = $this->makeProduct($setting);

        $this->makeStock($product, $location, [
            'quantity' => 1, 'quantity_tax' => 0, 'quantity_non_tax' => 1,
        ]);

        $this->actingAsUser($setting);

        $component = Livewire::test(BreakageProductTable::class, ['locationId' => $location->id])
            ->set('scanInput', 'BC-HDMI-1')
            ->call('processScan')
            ->assertSet('quantities.0', 1);

        $component->set('scanInput', 'BC-HDMI-1')
            ->call('processScan')
            ->assertSet('quantities.0', 1)
            ->assertSee('tidak mencukupi')
            ->assertDispatched('select-scan-input');
    }

    public function test_conversion_barcode_scan_increments_by_conversion_factor(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $product = $this->makeProduct($setting);
        $baseUnit = Unit::create(['name' => 'Pcs', 'short_name' => 'PCS']);
        $product->update(['base_unit_id' => $baseUnit->id]);
        $boxUnit = Unit::create(['name' => 'Box', 'short_name' => 'BOX']);

        ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $boxUnit->id,
            'base_unit_id' => $baseUnit->id,
            'conversion_factor' => 5,
            'barcode' => 'BC-BOX-1',
        ]);

        $this->makeStock($product, $location, [
            'quantity' => 20, 'quantity_tax' => 0, 'quantity_non_tax' => 20,
        ]);

        $this->actingAsUser($setting);

        Livewire::test(BreakageProductTable::class, ['locationId' => $location->id])
            ->set('scanInput', 'BC-BOX-1')
            ->call('processScan')
            ->assertCount('products', 1)
            ->assertSet('quantities.0', 5);
    }

    public function test_ambiguous_scan_opens_modal_and_selecting_candidate_applies_it(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);

        $product = $this->makeProduct($setting, [
            'product_name' => 'Item A', 'product_code' => 'SKU-A', 'barcode' => 'DUPLICATE-CODE',
        ]);

        $serial = ProductSerialNumber::create([
            'product_id' => $this->makeProduct($setting, [
                'product_name' => 'Item B', 'product_code' => 'SKU-B', 'barcode' => 'BC-B',
                'serial_number_required' => true,
            ])->id,
            'location_id' => $location->id,
            'serial_number' => 'DUPLICATE-CODE',
            'status' => 'ACTIVE',
        ]);

        $this->makeStock($product, $location, [
            'quantity' => 5, 'quantity_tax' => 0, 'quantity_non_tax' => 5,
        ]);

        $this->actingAsUser($setting);

        $component = Livewire::test(BreakageProductTable::class, ['locationId' => $location->id])
            ->set('scanInput', 'DUPLICATE-CODE')
            ->call('processScan')
            ->assertSet('showAmbiguityModal', true);

        $this->assertCount(2, $component->get('ambiguousCandidates'));

        $productCandidateIndex = collect($component->get('ambiguousCandidates'))
            ->search(fn ($c) => $c['type'] === 'product');

        $component->call('selectAmbiguousCandidate', $productCandidateIndex)
            ->assertSet('showAmbiguityModal', false)
            ->assertCount('products', 1)
            ->assertSet('quantities.0', 1)
            ->assertDispatched('restore-scanner-focus');
    }

    public function test_unknown_scan_dispatches_select_scan_input_for_correction(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $this->actingAsUser($setting);

        Livewire::test(BreakageProductTable::class, ['locationId' => $location->id])
            ->set('scanInput', 'NOT-FOUND-CODE')
            ->call('processScan')
            ->assertSee('tidak ditemukan')
            ->assertDispatched('select-scan-input');
    }

    public function test_product_search_modal_adds_row_and_rejects_duplicate(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $product = $this->makeProduct($setting);

        $this->makeStock($product, $location, [
            'quantity' => 5, 'quantity_tax' => 0, 'quantity_non_tax' => 5,
        ]);

        $this->actingAsUser($setting);

        $component = Livewire::test(BreakageProductTable::class, ['locationId' => $location->id])
            ->call('openSearchModal')
            ->set('searchTerm', 'Kabel')
            ->call('searchProducts');

        $this->assertCount(1, $component->get('searchResults'));

        $component->call('productSelected', ['id' => $product->id])
            ->assertCount('products', 1)
            ->assertSet('showSearchModal', false);

        // Duplicate via search: focuses existing row, does not add a second one
        $component->call('openSearchModal')
            ->call('productSelected', ['id' => $product->id])
            ->assertCount('products', 1)
            ->assertSee('sudah ada di daftar');
    }

    public function test_serialized_row_only_accepts_eligible_good_serial_at_selected_location(): void
    {
        $setting = $this->makeSetting(true);
        $location = $this->makeLocation($setting);
        $otherLocation = $this->makeLocation($setting);

        $product = $this->makeProduct($setting, [
            'product_name' => 'Laptop', 'product_code' => 'SKU-LT', 'barcode' => 'BC-LT',
            'serial_number_required' => true,
        ]);

        $tax = Tax::create(['name' => 'PPN', 'value' => 10]);

        $goodSerial = ProductSerialNumber::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'serial_number' => 'SN-GOOD-1', 'status' => 'ACTIVE', 'tax_id' => $tax->id,
        ]);

        $wrongLocationSerial = ProductSerialNumber::create([
            'product_id' => $product->id, 'location_id' => $otherLocation->id,
            'serial_number' => 'SN-WRONG-LOC', 'status' => 'ACTIVE', 'tax_id' => $tax->id,
        ]);

        $this->makeStock($product, $location, [
            'quantity' => 2, 'quantity_tax' => 2, 'quantity_non_tax' => 0,
        ]);

        $this->actingAsUser($setting);

        $component = Livewire::test(BreakageProductTable::class, ['locationId' => $location->id])
            ->set('scanInput', 'SN-GOOD-1')
            ->call('processScan')
            ->assertCount('products', 1)
            ->assertSet('quantities.0', 1);

        $component->call('openSerialModal', 0)
            ->set('rowSerialInput', 'SN-WRONG-LOC')
            ->call('addRowSerial')
            ->assertSet('quantities.0', 1)
            ->assertSet('rowSerialError', fn ($v) => !empty($v));
    }

    public function test_location_change_with_rows_requires_confirmation_then_clears_state(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $otherLocation = $this->makeLocation($setting);
        $product = $this->makeProduct($setting);

        $this->makeStock($product, $location, [
            'quantity' => 5, 'quantity_tax' => 0, 'quantity_non_tax' => 5,
        ]);

        $this->actingAsUser($setting);

        $component = Livewire::test(BreakageProductTable::class, ['locationId' => $location->id])
            ->set('scanInput', 'BC-HDMI-1')
            ->call('processScan')
            ->assertCount('products', 1);

        $component->call('locationDropdownSelected', 'location_id', $otherLocation->id)
            ->assertSet('showLocationConfirmModal', true)
            ->assertCount('products', 1);

        $component->call('confirmLocationChange')
            ->assertSet('showLocationConfirmModal', false)
            ->assertSet('locationId', $otherLocation->id)
            ->assertCount('products', 0);
    }

    public function test_location_change_cancel_restores_dropdown_and_keeps_rows(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $otherLocation = $this->makeLocation($setting);
        $product = $this->makeProduct($setting);

        $this->makeStock($product, $location, [
            'quantity' => 5, 'quantity_tax' => 0, 'quantity_non_tax' => 5,
        ]);

        $this->actingAsUser($setting);

        $component = Livewire::test(BreakageProductTable::class, ['locationId' => $location->id])
            ->set('scanInput', 'BC-HDMI-1')
            ->call('processScan')
            ->call('locationDropdownSelected', 'location_id', $otherLocation->id)
            ->assertSet('showLocationConfirmModal', true);

        $component->call('cancelLocationChange')
            ->assertSet('showLocationConfirmModal', false)
            ->assertSet('locationId', $location->id)
            ->assertCount('products', 1)
            ->assertDispatched('setSelectedLocation');
    }

    public function test_hydrates_from_old_input_preserving_single_quantity_across_validation_failure(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $product = $this->makeProduct($setting);

        $this->makeStock($product, $location, [
            'quantity' => 5, 'quantity_tax' => 0, 'quantity_non_tax' => 5,
        ]);

        $this->actingAsUser($setting);

        Livewire::test(BreakageProductTable::class, [
            'locationId' => $location->id,
            'product_ids' => [$product->id],
            'quantities_tax' => [0],
            'quantities_non_tax' => [3],
        ])
            ->assertCount('products', 1)
            ->assertSet('quantities.0', 3);
    }

    public function test_location_change_to_a_location_owned_by_another_setting_is_rejected(): void
    {
        $setting = $this->makeSetting();
        $otherSetting = $this->makeSetting();
        $otherLocation = $this->makeLocation($otherSetting);

        $this->actingAsUser($setting);

        Livewire::test(BreakageProductTable::class)
            ->call('locationDropdownSelected', 'location_id', $otherLocation->id)
            ->assertSet('locationId', null)
            ->assertSet('isPkp', null)
            ->assertSee('bukan milik pengaturan aktif');
    }

    public function test_search_and_scan_never_expose_a_product_from_another_setting(): void
    {
        $setting = $this->makeSetting();
        $otherSetting = $this->makeSetting();
        $location = $this->makeLocation($setting);

        $foreignProduct = $this->makeProduct($otherSetting, [
            'product_name' => 'Produk Setting Lain',
            'product_code' => 'SKU-FOREIGN',
            'barcode' => 'BC-FOREIGN',
        ]);

        $this->actingAsUser($setting);

        // Direct scan of a foreign-setting product's barcode must behave as not-found.
        Livewire::test(BreakageProductTable::class, ['locationId' => $location->id])
            ->set('scanInput', 'BC-FOREIGN')
            ->call('processScan')
            ->assertCount('products', 0)
            ->assertSee('tidak ditemukan');

        // Search modal must never return it either.
        $component = Livewire::test(BreakageProductTable::class, ['locationId' => $location->id])
            ->call('openSearchModal')
            ->set('searchTerm', 'Produk Setting Lain')
            ->call('searchProducts');

        $this->assertCount(0, $component->get('searchResults'));

        // A crafted direct productSelected() call for the foreign product ID must also be rejected.
        $component->call('productSelected', ['id' => $foreignProduct->id])
            ->assertCount('products', 0)
            ->assertSee('bukan milik pengaturan aktif');
    }

    public function test_locationId_property_cannot_be_set_directly_by_a_crafted_request(): void
    {
        $setting = $this->makeSetting();
        $otherSetting = $this->makeSetting();
        $otherLocation = $this->makeLocation($otherSetting);

        $this->actingAsUser($setting);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot update locked property: [locationId]');

        Livewire::test(BreakageProductTable::class)
            ->set('locationId', $otherLocation->id);
    }

    public function test_pendingLocationId_property_cannot_be_set_directly_by_a_crafted_request(): void
    {
        $setting = $this->makeSetting();
        $otherSetting = $this->makeSetting();
        $otherLocation = $this->makeLocation($otherSetting);

        $this->actingAsUser($setting);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot update locked property: [pendingLocationId]');

        Livewire::test(BreakageProductTable::class)
            ->set('pendingLocationId', $otherLocation->id);
    }

    /**
     * Even if pendingLocationId could somehow be forced to a foreign
     * location (e.g. a future refactor removes #[Locked]),
     * confirmLocationChange() -> applyLocationChange() must still reject it
     * server-side and must never disclose the foreign location's stock.
     */
    public function test_confirming_a_tampered_pending_location_id_via_reflection_is_still_rejected(): void
    {
        $setting = $this->makeSetting();
        $otherSetting = $this->makeSetting();
        $otherLocation = $this->makeLocation($otherSetting);
        $location = $this->makeLocation($setting);
        $product = $this->makeProduct($setting);

        $this->makeStock($product, $location, [
            'quantity' => 5, 'quantity_tax' => 0, 'quantity_non_tax' => 5,
        ]);

        $this->actingAsUser($setting);

        $instance = new BreakageProductTable();
        $instance->id = 'test-id';
        $instance->mount(locationId: $location->id);

        $ref = new \ReflectionProperty(BreakageProductTable::class, 'pendingLocationId');
        $ref->setAccessible(true);
        $ref->setValue($instance, $otherLocation->id);

        $instance->confirmLocationChange();

        $this->assertNull($instance->locationId);
        $this->assertNull($instance->isPkp);
        $this->assertStringContainsString('bukan milik pengaturan aktif', (string) $instance->feedbackMessage);
    }
}
