<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
// Removed redundant import
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductBundle;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * @group pos-critical-path
 */
class POSProductSearchScanTest extends TestCase
{
    use RefreshDatabase;

    private int $terminalSequence = 1;

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
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    public function test_search_endpoint_follows_pos_session_guard_when_cashier_has_no_active_session(): void
    {
        $setting = $this->createSetting('BIZ SEARCH NO SESSION');
        $cashier = $this->createUserForSetting(
            $setting,
            'POS SEARCH CASHIER NO SESSION',
            ['pos.access', 'pos.sell', 'pos.sessions.open']
        );

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell.products.search', ['q' => 'ABC']))
            ->assertRedirect(route('pos.sessions.create'))
            ->assertSessionHas('warning', 'Active POS session is required before accessing POS sell screen.');
    }

    public function test_search_auto_selects_exact_product_barcode_match(): void
    {
        $setting = $this->createSetting('BIZ SEARCH BARCODE');
        [$cashier, $allowedLocation] = $this->createCashierAndOpenSession($setting, 'POS SEARCH BARCODE');

        $product = $this->createStockedProduct(
            setting: $setting,
            location: $allowedLocation,
            code: 'SKU-BAR-001',
            name: 'Kopi Barcode',
            barcode: 'BAR-001',
            availableQty: 7,
            salePrice: 23500,
            serialRequired: false,
            createdBy: $cashier->id
        );

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.products.search', ['q' => 'bar-001']));

        $response->assertOk();
        $response->assertJsonPath('query', 'bar-001');
        $response->assertJsonPath('meta.auto_select_product_id', $product->id);
        $response->assertJsonPath('meta.result_count', 1);
        $response->assertJsonPath('results.0.id', $product->id);
        $response->assertJsonPath('results.0.matched_by', 'barcode_exact');
        $response->assertJsonPath('results.0.available_qty', 7);
        $response->assertJsonPath('results.0.serial_number_required', false);
        $response->assertJsonPath('results.0.sale_price', 23500);
    }

    public function test_search_auto_selects_exact_conversion_barcode_match(): void
    {
        $setting = $this->createSetting('BIZ SEARCH CONVERSION');
        [$cashier, $allowedLocation] = $this->createCashierAndOpenSession($setting, 'POS SEARCH CONVERSION');

        $product = $this->createStockedProduct(
            setting: $setting,
            location: $allowedLocation,
            code: 'SKU-CONV-001',
            name: 'Kopi Konversi',
            barcode: null,
            availableQty: 3,
            salePrice: 41500,
            serialRequired: false,
            createdBy: $cashier->id
        );

        $unit = Unit::create([
            'name' => 'Box',
            'short_name' => 'BOX',
        ]);

        ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'conversion_factor' => 1,
            'barcode' => 'CV-9000',
        ]);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.products.search', ['q' => 'cv-9000']));

        $response->assertOk();
        $response->assertJsonPath('meta.auto_select_product_id', $product->id);
        $response->assertJsonPath('results.0.id', $product->id);
        $response->assertJsonPath('results.0.matched_by', 'barcode_exact');
    }

    public function test_search_supports_sku_and_name_queries_with_deterministic_order_and_limit(): void
    {
        $setting = $this->createSetting('BIZ SEARCH SORT');
        [$cashier, $allowedLocation] = $this->createCashierAndOpenSession($setting, 'POS SEARCH SORT');

        $alpha = $this->createStockedProduct(
            setting: $setting,
            location: $allowedLocation,
            code: 'SKU-ALPHA-01',
            name: 'Kopi Alpha',
            barcode: 'A-01',
            availableQty: 5,
            salePrice: 10000,
            serialRequired: false,
            createdBy: $cashier->id
        );

        $beta = $this->createStockedProduct(
            setting: $setting,
            location: $allowedLocation,
            code: 'SKU-BETA-01',
            name: 'Kopi Beta',
            barcode: 'B-01',
            availableQty: 5,
            salePrice: 10000,
            serialRequired: false,
            createdBy: $cashier->id
        );

        $skuResponse = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.products.search', ['q' => 'SKU-BETA-01']));

        $skuResponse->assertOk();
        $skuResponse->assertJsonPath('results.0.id', $beta->id);
        $skuResponse->assertJsonPath('results.0.matched_by', 'sku_exact');

        $nameResponse = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.products.search', ['q' => 'kopi', 'limit' => 1]));

        $nameResponse->assertOk();
        $nameResponse->assertJsonPath('meta.limit', 1);
        $nameResponse->assertJsonPath('meta.result_count', 1);
        $nameResponse->assertJsonPath('results.0.id', $alpha->id);
        $nameResponse->assertJsonPath('results.0.matched_by', 'name_partial');
    }

    public function test_search_filters_out_products_stocked_only_in_disallowed_locations(): void
    {
        $setting = $this->createSetting('BIZ SEARCH SCOPE');
        $otherSetting = $this->createSetting('BIZ OTHER SCOPE');
        [$cashier, $allowedLocation] = $this->createCashierAndOpenSession($setting, 'POS SEARCH SCOPE');

        $included = $this->createStockedProduct(
            setting: $setting,
            location: $allowedLocation,
            code: 'SKU-IN-01',
            name: 'Produk Masuk',
            barcode: 'IN-01',
            availableQty: 4,
            salePrice: 20000,
            serialRequired: false,
            createdBy: $cashier->id
        );

        $disallowedLocation = Location::create([
            'name' => 'LOKASI TIDAK DIIZINKAN',
            'setting_id' => $otherSetting->id,
        ]);

        $excluded = $this->createStockedProduct(
            setting: $setting,
            location: $disallowedLocation,
            code: 'SKU-OUT-01',
            name: 'Produk Keluar',
            barcode: 'OUT-01',
            availableQty: 9,
            salePrice: 22000,
            serialRequired: false,
            createdBy: $cashier->id
        );

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.products.search', ['q' => 'produk']));

        $response->assertOk();
        $resultIds = collect($response->json('results'))->pluck('id')->all();

        $this->assertContains($included->id, $resultIds);
        $this->assertNotContains($excluded->id, $resultIds);
    }

    public function test_search_exposes_serial_required_and_bundle_parent_flags(): void
    {
        $setting = $this->createSetting('BIZ SEARCH SERIAL');
        [$cashier, $allowedLocation] = $this->createCashierAndOpenSession($setting, 'POS SEARCH SERIAL');

        $serialBundleParent = $this->createStockedProduct(
            setting: $setting,
            location: $allowedLocation,
            code: 'SKU-SERIAL-01',
            name: 'Produk Serial Bundle',
            barcode: 'SERIAL-01',
            availableQty: 2,
            salePrice: 88000,
            serialRequired: true,
            createdBy: $cashier->id
        );

        ProductBundle::create([
            'parent_product_id' => $serialBundleParent->id,
            'name' => 'Paket Serial',
        ]);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.products.search', ['q' => 'SERIAL-01']));

        $response->assertOk();
        $response->assertJsonPath('results.0.id', $serialBundleParent->id);
        $response->assertJsonPath('results.0.serial_number_required', true);
        $response->assertJsonPath('results.0.is_bundle_parent', true);
    }

    private function createSetting(string $name): Setting
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
            'pos_enabled' => true,
        ]);
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

    private function createCashierAndOpenSession(Setting $setting, string $roleSuffix): array
    {
        $cashier = $this->createUserForSetting(
            $setting,
            $roleSuffix . ' CASHIER',
            ['pos.access', 'pos.sell', 'pos.sessions.open']
        );

        $terminal = $this->createTerminalForSetting($setting);
        $allowedLocation = SalesLocationResolver::resolve((int) $terminal->setting_id);

        PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashier->id,
            'status' => PosSession::STATUS_OPEN,
            'opened_at' => now(),
            'opened_by' => $cashier->id,
            'opening_float_total' => 100000,
            'expected_cash_total' => 100000,
            'active_marker' => 1,
        ]);

        return [$cashier, $allowedLocation];
    }

    private function createTerminalForSetting(Setting $setting): PosTerminal
    {
        $sequence = $this->terminalSequence++;

        $location = Location::create([
            'name' => 'POS SEARCH LOC ' . $sequence,
            'setting_id' => $setting->id,
        ]);

        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-SEARCH-' . str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Search Terminal ' . $sequence,
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => 0,
            'require_pickup_supervisor_approval' => true,
        ]);

        return $terminal;
    }

    private function createStockedProduct(
        Setting $setting,
        Location $location,
        string $code,
        string $name,
        ?string $barcode,
        int $availableQty,
        float $salePrice,
        bool $serialRequired,
        int $createdBy
    ): Product {
        $category = Category::firstOrCreate(
            ['category_code' => 'CAT-' . $setting->id],
            [
                'category_name' => 'Kategori POS ' . $setting->id,
                'created_by' => $createdBy,
                'setting_id' => $setting->id,
            ]
        );

        $product = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'product_name' => $name,
            'product_code' => $code,
            'barcode' => $barcode,
            'product_quantity' => $availableQty,
            'product_cost' => 10000,
            'product_price' => $salePrice,
            'product_unit' => 'PCS',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => $serialRequired,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => $availableQty,
            'quantity_non_tax' => $availableQty,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => null,
        ]);

        ProductPrice::updateOrCreate([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
        ], [
            'sale_price' => $salePrice,
            'tier_1_price' => null,
            'tier_2_price' => null,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
            'purchase_tax_id' => null,
            'sale_tax_id' => null,
        ]);

        return $product->fresh();
    }
}
