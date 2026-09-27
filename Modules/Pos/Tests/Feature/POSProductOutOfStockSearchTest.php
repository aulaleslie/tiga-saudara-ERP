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
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class POSProductOutOfStockSearchTest extends TestCase
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

    public function test_search_includes_out_of_stock_products_within_scope(): void
    {
        $setting = $this->createSetting('BIZ OOS SEARCH');
        [$cashier, $allowedLocation] = $this->createCashierAndOpenSession($setting, 'POS OOS SEARCH');

        $inStock = $this->createStockedProduct($setting, $allowedLocation, 'SKU-IN', 'Produk Ada', 'BC-IN', 5, 1000, $cashier->id);
        $outOfStock = $this->createStockedProduct($setting, $allowedLocation, 'SKU-OOS', 'Produk Habis', 'BC-OOS', 0, 2000, $cashier->id);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.products.search', ['q' => 'Produk']));

        $response->assertOk();
        $resultIds = collect($response->json('results'))->pluck('id')->all();

        $this->assertContains($inStock->id, $resultIds, 'In-stock product should be in results');
        $this->assertContains($outOfStock->id, $resultIds, 'Out-of-stock product in scope should be in results');
        
        $oosResult = collect($response->json('results'))->firstWhere('id', $outOfStock->id);
        $this->assertEquals(0, $oosResult['available_qty']);
    }

    public function test_search_excludes_oos_products_outside_scope(): void
    {
        $setting = $this->createSetting('BIZ OOS SCOPE');
        $otherSetting = $this->createSetting('BIZ OTHER OOS');
        [$cashier, $allowedLocation] = $this->createCashierAndOpenSession($setting, 'POS OOS SCOPE');

        $otherLocation = Location::create([
            'name' => 'LOKASI LAIN',
            'setting_id' => $otherSetting->id,
        ]);

        // When Location is created, it might be auto-enabled for all settings via observer.
        // Explicitly disable it for our setting to test scope exclusion.
        \Modules\Setting\Entities\SettingSaleLocation::where('setting_id', $setting->id)
            ->where('location_id', $otherLocation->id)
            ->update(['is_enabled' => false]);

        \App\Support\SalesLocationResolver::forget($setting->id);

        // Product stocked ONLY in disallowed location
        $excludedOos = $this->createStockedProduct($setting, $otherLocation, 'SKU-EXCL', 'Produk Jauh', 'BC-EXCL', 0, 5000, $cashier->id);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.products.search', ['q' => 'Produk']));

        $response->assertOk();
        $resultIds = collect($response->json('results'))->pluck('id')->all();

        $this->assertNotContains($excludedOos->id, $resultIds, 'OOS product outside scope should NOT be in results');
    }

    public function test_add_to_cart_rejects_oos_product(): void
    {
        $setting = $this->createSetting('BIZ OOS REJECT');
        [$cashier, $allowedLocation] = $this->createCashierAndOpenSession($setting, 'POS OOS REJECT');
        
        $oosProduct = $this->createStockedProduct($setting, $allowedLocation, 'SKU-OOS-ADD', 'OOS Product', 'BC-OOS-ADD', 0, 5000, $cashier->id);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $oosProduct->id,
                'qty' => 1,
            ]);

        $response->assertStatus(422);
        // The message from PosCartService::resolveCartProduct line 931
        $response->assertJsonPath('message', 'Product stock is not available in configured sales locations.');
    }

    public function test_auto_select_ignores_exact_barcode_match_with_zero_stock(): void
    {
        $setting = $this->createSetting('BIZ OOS AUTO');
        [$cashier, $allowedLocation] = $this->createCashierAndOpenSession($setting, 'POS OOS AUTO');

        $outOfStock = $this->createStockedProduct($setting, $allowedLocation, 'SKU-OOS-BC', 'Produk Habis BC', 'BC-999', 0, 3000, $cashier->id);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.products.search', ['q' => 'BC-999']));

        $response->assertOk();
        $response->assertJsonPath('meta.auto_select_product_id', null, 'Should NOT auto-select OOS product');
        $response->assertJsonPath('results.0.id', $outOfStock->id);
    }

    public function test_search_finds_product_without_any_stock_records(): void
    {
        $setting = $this->createSetting('BIZ NO STOCK REC');
        [$cashier, $allowedLocation] = $this->createCashierAndOpenSession($setting, 'POS NO STOCK REC');

        // Create product but DO NOT create any ProductStock records
        $category = Category::firstOrCreate(['category_code' => 'CAT'], ['category_name' => 'CAT', 'setting_id' => $setting->id, 'created_by' => $cashier->id]);
        $product = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'product_name' => 'KERTAS SIDU TEST',
            'product_code' => 'SKU-KERTAS',
            'barcode' => 'BC-KERTAS',
            'product_quantity' => 0,
            'product_cost' => 4000,
            'product_price' => 5000,
            'stock_managed' => true,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'sale_price' => 5000,
        ]);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.products.search', ['q' => 'ker']));

        $response->assertOk();
        $resultIds = collect($response->json('results'))->pluck('id')->all();

        $this->assertContains($product->id, $resultIds, 'Product without stock records should be found if it belongs to setting');
        
        $result = collect($response->json('results'))->firstWhere('id', $product->id);
        $this->assertEquals(0, $result['available_qty']);
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

    private function createCashierAndOpenSession(Setting $setting, string $roleSuffix): array
    {
        $role = Role::firstOrCreate(['name' => $roleSuffix . ' CASHIER']);
        $role->syncPermissions(['pos.access', 'pos.sell', 'pos.sessions.open']);

        $cashier = User::factory()->create();
        $cashier->assignRole($role);
        $cashier->settings()->attach($setting->id, ['role_id' => $role->id]);

        $location = Location::create([
            'name' => 'POS OOS LOC ' . ($this->terminalSequence),
            'setting_id' => $setting->id,
        ]);

        \Modules\Setting\Entities\SettingSaleLocation::create([
            'setting_id' => $setting->id,
            'location_id' => $location->id,
            'is_enabled' => true,
        ]);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'T-' . ($this->terminalSequence++),
            'name' => 'Terminal OOS',
            'is_active' => true,
        ]);

        PosTerminalPolicy::create(['terminal_id' => $terminal->id]);

        SalesLocationResolver::forget($setting->id);

        PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $cashier->id,
            'status' => PosSession::STATUS_OPEN,
            'opened_at' => now(),
            'opened_by' => $cashier->id,
            'active_marker' => 1,
        ]);

        return [$cashier, $location];
    }

    private function createStockedProduct(Setting $setting, Location $location, string $code, string $name, string $barcode, int $qty, float $price, int $userId): Product
    {
        $category = Category::firstOrCreate(['category_code' => 'CAT'], ['category_name' => 'CAT', 'setting_id' => $setting->id, 'created_by' => $userId]);
        
        $product = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'product_name' => $name,
            'product_code' => $code,
            'barcode' => $barcode,
            'product_quantity' => $qty,
            'product_cost' => 500,
            'product_price' => $price,
            'stock_managed' => true,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => $qty,
            'quantity_non_tax' => $qty,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'sale_price' => $price,
        ]);

        return $product->fresh();
    }
}
