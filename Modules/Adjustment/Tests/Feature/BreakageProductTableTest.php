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
use Spatie\Permission\Models\Permission;
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

    private function actingAsUserWithSystemStockPermission(Setting $setting): void
    {
        $user = User::factory()->create(['is_active' => 1]);
        Permission::firstOrCreate(['name' => 'adjustments.view-system-stock', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'stock-viewer-' . uniqid(), 'guard_name' => 'web']);
        $role->givePermissionTo('adjustments.view-system-stock');
        $user->assignRole($role);
        $user->settings()->attach($setting->id, ['role_id' => $role->id]);

        $this->actingAs($user);
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

    /**
     * The product catalogue is global -- Product::setting_id is a legacy
     * column and never gates eligibility (see
     * StockOpnameApprovalService::approve()'s documented rule). A product
     * whose legacy setting_id belongs to a different setting than the
     * currently active one must still be scannable, searchable, and
     * selectable for breakage as long as it is active and stock-managed and
     * the selected location (which does belong to the active setting) is
     * used to evaluate its available good stock.
     */
    public function test_globally_shared_product_with_a_different_legacy_setting_id_is_still_eligible(): void
    {
        $setting = $this->makeSetting();
        $otherSetting = $this->makeSetting();
        $location = $this->makeLocation($setting);

        $sharedProduct = $this->makeProduct($otherSetting, [
            'product_name' => 'Produk Bersama',
            'product_code' => 'SKU-SHARED',
            'barcode' => 'BC-SHARED',
        ]);

        $this->makeStock($sharedProduct, $location, [
            'quantity' => 5, 'quantity_tax' => 0, 'quantity_non_tax' => 5,
        ]);

        $this->actingAsUser($setting);

        // Scanning its barcode at the owned location resolves and increments as usual.
        // (Available good stock is no longer part of public component state --
        // see currentStockBuckets()/buildStockDisplayMap() -- so eligibility
        // is asserted through the actual increment outcome instead.)
        Livewire::test(BreakageProductTable::class, ['locationId' => $location->id])
            ->set('scanInput', 'BC-SHARED')
            ->call('processScan')
            ->assertCount('products', 1)
            ->assertSet('quantities.0', 1);

        // Search modal returns it too.
        $component = Livewire::test(BreakageProductTable::class, ['locationId' => $location->id])
            ->call('openSearchModal')
            ->set('searchTerm', 'Produk Bersama')
            ->call('searchProducts');

        $this->assertCount(1, $component->get('searchResults'));

        $component->call('productSelected', ['id' => $sharedProduct->id])
            ->assertCount('products', 1);
    }

    /**
     * The location-ownership boundary is still enforced independently of
     * product eligibility: a product that only has stock at a location
     * belonging to a DIFFERENT setting must not be reachable through the
     * active setting's editor, because its stock can only ever be queried
     * against the currently selected (owned) location -- there is simply no
     * ProductStock row to move there, regardless of the product's own
     * eligibility.
     */
    public function test_product_with_stock_only_at_another_settings_location_shows_no_available_stock_here(): void
    {
        $setting = $this->makeSetting();
        $otherSetting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $otherLocation = $this->makeLocation($otherSetting);

        $product = $this->makeProduct($setting, [
            'product_name' => 'Produk Lokasi Lain', 'product_code' => 'SKU-OTHERLOC', 'barcode' => 'BC-OTHERLOC',
        ]);

        $this->makeStock($product, $otherLocation, [
            'quantity' => 5, 'quantity_tax' => 0, 'quantity_non_tax' => 5,
        ]);

        $this->actingAsUser($setting);

        // The scan still resolves the product (it is globally eligible), but
        // the owned location has no stock row for it, so available good is 0
        // and any increment attempt is rejected for insufficient stock.
        Livewire::test(BreakageProductTable::class, ['locationId' => $location->id])
            ->set('scanInput', 'BC-OTHERLOC')
            ->call('processScan')
            ->assertCount('products', 1)
            ->assertSet('quantities.0', 0)
            ->assertSee('tidak mencukupi');
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

    /**
     * Location stock figures (available good, tax/non-tax/broken buckets)
     * must never reach a viewer without adjustments.view-system-stock --
     * not in the rendered table (columns hidden), and not anywhere in the
     * public Livewire component state, since the ENTIRE public state is
     * serialized to the client on every response regardless of what the
     * Blade template happens to render.
     */
    public function test_stock_figures_are_never_exposed_without_view_system_stock_permission(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $product = $this->makeProduct($setting, [
            'product_name' => 'Rahasia Stok', 'product_code' => 'SKU-SECRET-STOCK', 'barcode' => 'BC-SECRETSTOCK',
        ]);

        $this->makeStock($product, $location, [
            'quantity' => 42, 'quantity_tax' => 0, 'quantity_non_tax' => 42,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 7, 'broken_quantity' => 7,
        ]);

        $this->actingAsUser($setting);

        $component = Livewire::test(BreakageProductTable::class, ['locationId' => $location->id])
            ->set('scanInput', 'BC-SECRETSTOCK')
            ->call('processScan')
            ->assertCount('products', 1);

        // Neither raw stock quantities nor the "42"/"7" figures ever appear
        // in the rendered HTML for an unauthorized viewer.
        $component->assertDontSee('Stok Baik Tersedia');
        $component->assertDontSee('Stok Rusak Saat Ini');
        $component->assertDontSee('42');
        $component->assertDontSee(', 7 ', false);

        // The public component state itself must not carry stock figures,
        // since Livewire serializes all public properties to the client on
        // every response irrespective of what the Blade template renders.
        // stockByProductId is deliberately a render()-time view variable
        // (never a public property), so it is not part of the wire payload
        // at all for this component -- confirmed by inspecting $products.
        $productRow = $component->get('products')[0];
        $this->assertArrayNotHasKey('available_good', $productRow);
        $this->assertArrayNotHasKey('quantity_tax', $productRow);
        $this->assertArrayNotHasKey('quantity_non_tax', $productRow);
        $this->assertArrayNotHasKey('broken_quantity_tax', $productRow);
        $this->assertArrayNotHasKey('broken_quantity_non_tax', $productRow);
    }

    public function test_stock_figures_are_shown_to_a_user_with_view_system_stock_permission(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $product = $this->makeProduct($setting, [
            'product_name' => 'Stok Terlihat', 'product_code' => 'SKU-VISIBLE-STOCK', 'barcode' => 'BC-VISIBLESTOCK',
        ]);

        $this->makeStock($product, $location, [
            'quantity' => 42, 'quantity_tax' => 0, 'quantity_non_tax' => 42,
            'broken_quantity_tax' => 0, 'broken_quantity_non_tax' => 7, 'broken_quantity' => 7,
        ]);

        $this->actingAsUserWithSystemStockPermission($setting);

        $component = Livewire::test(BreakageProductTable::class, ['locationId' => $location->id])
            ->set('scanInput', 'BC-VISIBLESTOCK')
            ->call('processScan')
            ->assertCount('products', 1);

        $component->assertSee('Stok Baik Tersedia');
        $component->assertSee('Stok Rusak Saat Ini');
        $component->assertSee('42');
        $component->assertSee('7');
    }

    /**
     * Every scan (server-side) round-trip mutates $this->quantities directly
     * and is dehydrated/rehydrated by Livewire between calls, so two
     * server-side calls in sequence always see each other's effect --
     * scanning the same barcode twice increments 0 -> 1 -> 2. The client-side
     * scan queue in breakage-product-table.blade.php (breakageScanQueue())
     * exists to guarantee two BROWSER-level rapid Enter presses are also
     * always sent to the server as two such sequential round trips (rather
     * than two overlapping requests racing against the same snapshot) --
     * that ordering guarantee is a JS concern the PHP test suite cannot
     * exercise directly, but this proves the server-side contract the queue
     * relies on: each completed processScan() call is idempotent-free and
     * additive against whatever state the previous completed call left.
     */
    public function test_repeated_sequential_scans_of_the_same_barcode_each_increment_exactly_once(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $product = $this->makeProduct($setting);

        $this->makeStock($product, $location, [
            'quantity' => 10, 'quantity_tax' => 0, 'quantity_non_tax' => 10,
        ]);

        $this->actingAsUser($setting);

        $component = Livewire::test(BreakageProductTable::class, ['locationId' => $location->id]);

        $component->call('processScan', 'BC-HDMI-1')->assertSet('quantities.0', 1);
        $component->call('processScan', 'BC-HDMI-1')->assertSet('quantities.0', 2);
        $component->call('processScan', 'BC-HDMI-1')->assertSet('quantities.0', 3);

        $this->assertCount(1, $component->get('products'));
    }

    /**
     * processScan(code) must use the explicit code argument rather than the
     * synced $this->scanInput property, so the client-side queue can drive
     * a call by value even if scanInput has already been reset/changed by
     * the time a queued call's turn arrives.
     */
    public function test_processScan_uses_the_explicit_code_argument_over_stale_scan_input_property(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $product = $this->makeProduct($setting);

        $this->makeStock($product, $location, [
            'quantity' => 5, 'quantity_tax' => 0, 'quantity_non_tax' => 5,
        ]);

        $this->actingAsUser($setting);

        Livewire::test(BreakageProductTable::class, ['locationId' => $location->id])
            ->set('scanInput', 'SOME-STALE-VALUE-NOT-A-REAL-BARCODE')
            ->call('processScan', 'BC-HDMI-1')
            ->assertCount('products', 1)
            ->assertSet('quantities.0', 1);
    }

    /**
     * The exact available-stock figure must never leak into the public
     * feedbackMessage property for a user without
     * adjustments.view-system-stock: feedbackMessage is serialized to every
     * viewer on every response exactly like the removed stock columns, so
     * the shortage warning shown on an over-scan must be generic for such a
     * user and precise only for an authorized one.
     */
    public function test_shortage_feedback_hides_exact_stock_figure_without_view_system_stock_permission(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $product = $this->makeProduct($setting);

        $this->makeStock($product, $location, [
            'quantity' => 3, 'quantity_tax' => 0, 'quantity_non_tax' => 3,
        ]);

        $this->actingAsUser($setting);

        $component = Livewire::test(BreakageProductTable::class, ['locationId' => $location->id])
            ->call('processScan', 'BC-HDMI-1')
            ->call('processScan', 'BC-HDMI-1')
            ->call('processScan', 'BC-HDMI-1')
            // 4th scan exceeds the 3 available -- must reject with a
            // generic message, never disclosing "(tersedia 3)".
            ->call('processScan', 'BC-HDMI-1');

        $component->assertSet('quantities.0', 3);
        $component->assertSee('tidak mencukupi');
        $component->assertDontSee('tersedia 3');
        $component->assertDontSee('(tersedia');
    }

    public function test_shortage_feedback_shows_exact_stock_figure_with_view_system_stock_permission(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $product = $this->makeProduct($setting);

        $this->makeStock($product, $location, [
            'quantity' => 3, 'quantity_tax' => 0, 'quantity_non_tax' => 3,
        ]);

        $this->actingAsUserWithSystemStockPermission($setting);

        $component = Livewire::test(BreakageProductTable::class, ['locationId' => $location->id])
            ->call('processScan', 'BC-HDMI-1')
            ->call('processScan', 'BC-HDMI-1')
            ->call('processScan', 'BC-HDMI-1')
            ->call('processScan', 'BC-HDMI-1');

        $component->assertSet('quantities.0', 3);
        $component->assertSee('tersedia 3');
    }
}
