<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\Pos\Entities\PosSession;
use Modules\Pos\Entities\PosTerminal;
use Modules\Pos\Entities\PosTerminalPolicy;
use Modules\Product\Entities\Category;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductPrice;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * @group pos-critical-path
 */
class POSScanResolveEndpointTest extends TestCase
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

    public function test_scan_resolve_requires_active_session(): void
    {
        $setting = $this->createSetting('SCAN RESOLVE NO SESSION');
        $cashier = $this->createUserForSetting($setting, 'SCAN RESOLVE CASHIER NO SESSION', ['pos.access', 'pos.sell']);

        $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.search.resolve', ['q' => 'test']))
            ->assertRedirect(route('pos.sessions.create'));
    }

    public function test_exact_product_barcode_returns_product_exact(): void
    {
        $setting = $this->createSetting('SCAN RESOLVE BARCODE');
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'SCAN RESOLVE BARCODE');

        $product = $this->createStockedProduct($setting, $location, 'SKU-SCAN-001', 'Produk Scan', 50000, $cashier->id);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.search.resolve', ['q' => $product->barcode]));

        $response->assertOk()
            ->assertJsonPath('type', 'product_exact')
            ->assertJsonPath('product.id', $product->id)
            ->assertJsonPath('product.product_name', 'Produk Scan');
    }

    public function test_exact_product_sku_returns_product_exact(): void
    {
        $setting = $this->createSetting('SCAN RESOLVE SKU');
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'SCAN RESOLVE SKU');

        $product = $this->createStockedProduct($setting, $location, 'SKU-SCAN-002', 'Produk SKU', 75000, $cashier->id);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.search.resolve', ['q' => 'SKU-SCAN-002']));

        $response->assertOk()
            ->assertJsonPath('type', 'product_exact')
            ->assertJsonPath('product.product_code', 'SKU-SCAN-002');
    }

    public function test_ambiguous_query_returns_ambiguous(): void
    {
        $setting = $this->createSetting('SCAN RESOLVE AMBIG');
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'SCAN RESOLVE AMBIG');

        $this->createStockedProduct($setting, $location, 'SKU-AMBIG-A', 'Kopi', 10000, $cashier->id);
        $this->createStockedProduct($setting, $location, 'SKU-AMBIG-B', 'Kopi Hitam', 12000, $cashier->id);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.search.resolve', ['q' => 'kopi']));

        $response->assertOk()
            ->assertJsonPath('type', 'ambiguous')
            ->assertJsonPath('result_count', 2);
    }

    public function test_no_match_returns_none(): void
    {
        $setting = $this->createSetting('SCAN RESOLVE NONE');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'SCAN RESOLVE NONE');

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.search.resolve', ['q' => 'nonexistent-product-xyz']));

        $response->assertOk()
            ->assertJsonPath('type', 'none');
    }

    /**
     * POS-003 Regression: POST /pos/sell/search/resolve should not be allowed (405).
     * Frontend sends POST (HTTP method mismatch), but route is GET-only.
     * This ensures the method guard is actively enforced.
     */
    public function test_post_to_scan_resolve_endpoint_is_not_allowed(): void
    {
        $setting = $this->createSetting('SCAN RESOLVE POST GUARD');
        [$cashier] = $this->createCashierAndOpenSession($setting, 'SCAN RESOLVE POST GUARD');

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.search.resolve', ['q' => 'test']));

        $response->assertStatus(405);
    }

    // --- Helpers ---

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

    /**
     * @return array{0: User, 1: Location}
     */
    private function createCashierAndOpenSession(Setting $setting, string $roleSuffix): array
    {
        $cashier = $this->createUserForSetting(
            $setting,
            $roleSuffix . ' CASHIER',
            ['pos.access', 'pos.sell', 'pos.sessions.open']
        );

        $terminal = $this->createTerminalForSetting($setting);
        $location = SalesLocationResolver::resolve((int) $terminal->setting_id);

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

        return [$cashier, $location];
    }

    private function createTerminalForSetting(Setting $setting): PosTerminal
    {
        $sequence = $this->terminalSequence++;

        $location = Location::create([
            'name' => 'SCAN LOC ' . $sequence,
            'setting_id' => $setting->id,
        ]);

        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-SCAN-' . str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Scan Terminal ' . $sequence,
            'is_active' => true,
        ]);

        PosTerminalPolicy::create([
            'terminal_id' => $terminal->id,
            'require_session_open' => true,
            'require_opening_float' => true,
            'allow_total_only_float_input' => true,
            'close_variance_approval_threshold' => 0,
            'require_pickup_supervisor_approval' => true,
            'cash_threshold' => 50000,
        ]);

        return $terminal;
    }

    private function createStockedProduct(
        Setting $setting,
        Location $location,
        string $code,
        string $name,
        float $salePrice,
        int $createdBy
    ): Product {
        $category = Category::firstOrCreate(
            ['category_code' => 'SCAN-CAT-' . $setting->id],
            [
                'category_name' => 'Scan Category ' . $setting->id,
                'created_by' => $createdBy,
                'setting_id' => $setting->id,
            ]
        );

        $unit = Unit::firstOrCreate([
            'name' => 'Piece',
            'short_name' => 'PCS',
        ]);

        $product = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => $name,
            'product_code' => $code,
            'barcode' => $code . '-BC',
            'product_quantity' => 100,
            'product_cost' => 5000,
            'product_price' => $salePrice,
            'product_unit' => 'PCS',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => false,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 100,
            'quantity_non_tax' => 100,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
            'tax_id' => null,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'sale_price' => $salePrice,
            'tier_1_price' => null,
            'tier_2_price' => null,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
        ]);

        return $product->fresh();
    }
}
