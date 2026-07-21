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
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Product\Entities\ProductUnitConversionPrice;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Setting\Entities\Unit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * @group pos-phase2-conversion-pricing
 */
class POSPhase2ConversionPricingTest extends TestCase
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

    /**
     * WI-7: Same conversion barcode resolves to same product across two settings
     */
    public function test_same_conversion_barcode_resolves_across_settings(): void
    {
        $setting1 = $this->createSetting('PHASE2-CONV-1');
        $setting2 = $this->createSetting('PHASE2-CONV-2');

        [$cashier1, $location1] = $this->createCashierAndOpenSession($setting1, 'CASHIER-CONV-1');
        [$cashier2, $location2] = $this->createCashierAndOpenSession($setting2, 'CASHIER-CONV-2');

        // Create same product in both settings (different product IDs)
        $product1 = $this->createStockedProduct($setting1, $location1, 'SKU-PHASE2-001', 'Produk Konversi Lintas', 50000, $cashier1->id);
        $product2 = $this->createStockedProduct($setting2, $location2, 'SKU-PHASE2-001', 'Produk Konversi Lintas', 50000, $cashier2->id);

        // Create conversion with same barcode in both products
        $unit = Unit::firstOrCreate(['name' => 'Box', 'short_name' => 'BX']);
        $convBarcode = 'CONV-LINTAS-001';

        $conv1 = ProductUnitConversion::create([
            'product_id' => $product1->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $product1->base_unit_id,
            'conversion_factor' => 12,
            'barcode' => $convBarcode,
        ]);

        $conv2 = ProductUnitConversion::create([
            'product_id' => $product2->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $product2->base_unit_id,
            'conversion_factor' => 12,
            'barcode' => $convBarcode,
        ]);

        // Scan in setting 1
        $response1 = $this->actingAs($cashier1)
            ->withSession(['setting_id' => $setting1->id])
            ->getJson(route('pos.sell.search.resolve', ['q' => $convBarcode]));

        $response1->assertOk()
            ->assertJsonPath('product.id', $product1->id)
            ->assertJsonPath('product.resolved_via', 'conversion_barcode')
            ->assertJsonPath('product.conversion.id', $conv1->id);

        // Scan in setting 2 - should resolve to different product (product2)
        $response2 = $this->actingAs($cashier2)
            ->withSession(['setting_id' => $setting2->id])
            ->getJson(route('pos.sell.search.resolve', ['q' => $convBarcode]));

        $response2->assertOk()
            ->assertJsonPath('product.id', $product2->id)
            ->assertJsonPath('product.resolved_via', 'conversion_barcode')
            ->assertJsonPath('product.conversion.id', $conv2->id);
    }

    /**
     * WI-7: Conversion price differs by setting for same barcode
     */
    public function test_conversion_price_differs_by_setting(): void
    {
        $setting1 = $this->createSetting('PHASE2-PRICE-DIFF-1');
        $setting2 = $this->createSetting('PHASE2-PRICE-DIFF-2');

        [$cashier1, $location1] = $this->createCashierAndOpenSession($setting1, 'CASHIER-DIFF-1');
        [$cashier2, $location2] = $this->createCashierAndOpenSession($setting2, 'CASHIER-DIFF-2');

        // Same product in both settings
        $product1 = $this->createStockedProduct($setting1, $location1, 'SKU-DIFF-001', 'Produk Harga Berbeda', 50000, $cashier1->id);
        $product2 = $this->createStockedProduct($setting2, $location2, 'SKU-DIFF-001', 'Produk Harga Berbeda', 50000, $cashier2->id);

        $unit = Unit::firstOrCreate(['name' => 'Pack2', 'short_name' => 'PK2']);
        $convBarcode = 'CONV-HARGA-BERBEDA';

        // Create conversions
        $conv1 = ProductUnitConversion::create([
            'product_id' => $product1->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $product1->base_unit_id,
            'conversion_factor' => 10,
            'barcode' => $convBarcode,
        ]);

        $conv2 = ProductUnitConversion::create([
            'product_id' => $product2->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $product2->base_unit_id,
            'conversion_factor' => 10,
            'barcode' => $convBarcode,
        ]);

        // Set different prices for the same conversion unit in different settings
        ProductUnitConversionPrice::create([
            'product_unit_conversion_id' => $conv1->id,
            'setting_id' => $setting1->id,
            'price' => 450000, // Setting 1 price
        ]);

        ProductUnitConversionPrice::create([
            'product_unit_conversion_id' => $conv2->id,
            'setting_id' => $setting2->id,
            'price' => 500000, // Setting 2 price (different)
        ]);

        // Resolve in setting 1
        $response1 = $this->actingAs($cashier1)
            ->withSession(['setting_id' => $setting1->id])
            ->getJson(route('pos.sell.search.resolve', ['q' => $convBarcode]));

        $response1->assertOk()
            ->assertJsonPath('product.conversion.price_for_setting', 450000)
            ->assertJsonPath('product.conversion.price_source', 'conversion_price');

        // Resolve in setting 2
        $response2 = $this->actingAs($cashier2)
            ->withSession(['setting_id' => $setting2->id])
            ->getJson(route('pos.sell.search.resolve', ['q' => $convBarcode]));

        $response2->assertOk()
            ->assertJsonPath('product.conversion.price_for_setting', 500000)
            ->assertJsonPath('product.conversion.price_source', 'conversion_price');
    }

    /**
     * WI-7: Fallback to base product price when conversion price row is missing
     */
    public function test_fallback_to_base_price_when_conversion_price_missing(): void
    {
        $setting = $this->createSetting('PHASE2-FALLBACK');
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'CASHIER-FALLBACK');

        $product = $this->createStockedProduct($setting, $location, 'SKU-FALLBACK-001', 'Produk Fallback', 75000, $cashier->id);

        $unit = Unit::firstOrCreate(['name' => 'Carton2', 'short_name' => 'CTN2']);
        $convBarcode = 'CONV-NO-PRICE';

        // Create conversion WITHOUT adding price to product_unit_conversion_prices
        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $product->base_unit_id,
            'conversion_factor' => 24,
            'barcode' => $convBarcode,
        ]);

        // Note: intentionally NOT creating ProductUnitConversionPrice row

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.search.resolve', ['q' => $convBarcode]));

        $response->assertOk()
            ->assertJsonPath('product.conversion.id', $conversion->id)
            ->assertJsonPath('product.conversion.price_source', 'base_fallback');
            
        $this->assertEquals(75000, $response->json('product.conversion.price_for_setting'));
    }

    /**
     * WI-7: Cart add with conversion_id uses packed pricing
     */
    public function test_cart_add_with_conversion_uses_packed_pricing(): void
    {
        $setting = $this->createSetting('PHASE2-CART-CONV');
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'CASHIER-CART-CONV');

        $product = $this->createStockedProduct($setting, $location, 'SKU-CART-CONV-001', 'Produk Cart Konversi', 100000, $cashier->id);

        $unit = Unit::firstOrCreate(['name' => 'Dozen', 'short_name' => 'DZN']);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $product->base_unit_id,
            'conversion_factor' => 12,
            'barcode' => 'CONV-CART-001',
        ]);

        // Set conversion price
        ProductUnitConversionPrice::create([
            'product_unit_conversion_id' => $conversion->id,
            'setting_id' => $setting->id,
            'price' => 1050000, // 12 × ~87.5k
        ]);

        // Add to cart with conversion_id
        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $product->id,
                'qty' => 1,
                'conversion_id' => $conversion->id,
            ]);

        $response->assertOk();
        $snapshot = $response->json('cart_snapshot');

        $this->assertCount(1, $snapshot['lines']);
        $line = reset($snapshot['lines']);
        
        $this->assertEquals(1050000, $line['line_total']); // line_total is in minor units (1050000)
        $this->assertEquals($conversion->id, $line['conversion_id']);
        $this->assertEquals('PACKED', $line['price_source']);
        $this->assertEquals(12, $line['qty']); // seeded to 12
    }

    /**
     * WI-7: Cart add without conversion_id uses base product price
     */
    public function test_cart_add_without_conversion_uses_base_price(): void
    {
        $setting = $this->createSetting('PHASE2-CART-BASE');
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'CASHIER-CART-BASE');

        $product = $this->createStockedProduct($setting, $location, 'SKU-CART-BASE-001', 'Produk Cart Base', 80000, $cashier->id);

        // Add to cart WITHOUT conversion_id
        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $product->id,
                'qty' => 1,
            ]);

        $response->assertOk();
        $snapshot = $response->json('cart_snapshot');

        $this->assertCount(1, $snapshot['lines']);
        $line = reset($snapshot['lines']);
        $this->assertEquals(80000, $line['unit_price']); // Check price matches base product price
        $this->assertNull($line['conversion_id']);
        $this->assertEquals('BASE', $line['price_source']);
    }

    /**
     * WI-7: Base and conversion lines merge (re-pack into single line)
     */
    public function test_base_and_conversion_lines_merge_into_repacked_line(): void
    {
        $setting = $this->createSetting('PHASE2-MERGE');
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'CASHIER-MERGE');

        $product = $this->createStockedProduct($setting, $location, 'SKU-MERGE-001', 'Produk Merge', 60000, $cashier->id);

        $unit = Unit::firstOrCreate(['name' => 'Bundle', 'short_name' => 'BDL']);

        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $product->base_unit_id,
            'conversion_factor' => 5,
            'barcode' => 'CONV-MERGE-001',
        ]);

        ProductUnitConversionPrice::create([
            'product_unit_conversion_id' => $conversion->id,
            'setting_id' => $setting->id,
            'price' => 280000,
        ]);

        // Add base product
        $response1 = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $product->id,
                'qty' => 2,
            ]);

        $response1->assertOk();
        $snapshot1 = $response1->json('cart_snapshot');
        $this->assertCount(1, $snapshot1['lines']);

        // Add same product via conversion (qty 1 * factor 5 = 5 base units)
        $response2 = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $product->id,
                'qty' => 1,
                'conversion_id' => $conversion->id,
            ]);

        $response2->assertOk();
        $snapshot2 = $response2->json('cart_snapshot');

        // Should have 1 merged line
        $this->assertCount(1, $snapshot2['lines']);
        $lines = array_values($snapshot2['lines']);
        $line = $lines[0];
        
        $this->assertEquals(7, $line['qty']);
        $this->assertEquals('PACKED', $line['price_source']);
        
        // 1 box * 280k + 2 loose * 60k = 400000
        $this->assertEquals(400000, $line['line_total']);
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
            'name' => 'PHASE2 LOC ' . $sequence,
            'setting_id' => $setting->id,
        ]);

        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-PHASE2-' . str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Phase2 Terminal ' . $sequence,
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
            ['category_code' => 'PHASE2-CAT-' . $setting->id],
            [
                'category_name' => 'Phase2 Category ' . $setting->id,
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
            'product_quantity' => 1000,
            'product_cost' => 0,
            'product_price' => $salePrice,
            'product_unit' => 'PCS',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => false,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'sale_price' => $salePrice,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 1000,
            'quantity_non_tax' => 1000, // Pre-existing schema constraint fix
            'quantity_tax' => 0, // Additional schema constraint fix
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
        ]);

        return $product;
    }
}
