<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use App\Support\SalesLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Currency\Entities\Currency;
use Modules\People\Entities\Customer;
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
 * @group pos-tier-bypass-conversion-pricing
 */
class PosTierBypassConversionPricingTest extends TestCase
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
     * Task 4.2 & 4.3: Test sequence: 8000 x 1, qty 12 at conversion total 85000, then reseller selection producing exactly 78999.96.
     * Also tests clearing customer back to non-tier packing, and tier price fallback bypassing conversion.
     */
    public function test_packed_line_repricing_sequence_for_reseller_and_wholesaler(): void
    {
        $setting = $this->createSetting('TIER-BYPASS-SETTING');
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'CASHIER-BYPASS');

        // Create product: base_price = 8000 (8000.00), tier_1_price (WHOLESALER) = 7500 (7500.00), tier_2_price (RESELLER) = 6583.33
        $product = $this->createStockedProductWithTiers(
            $setting,
            $location,
            'SKU-BYPASS-001',
            'Produk Tier Bypass',
            8000.00,
            7500.00,
            6583.33,
            $cashier->id
        );

        $unit = Unit::firstOrCreate(['name' => 'BoxBypass', 'short_name' => 'BXB']);
        $conversion = ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $product->base_unit_id,
            'conversion_factor' => 12,
            'barcode' => 'CONV-BYPASS-001',
        ]);

        ProductUnitConversionPrice::create([
            'product_unit_conversion_id' => $conversion->id,
            'setting_id' => $setting->id,
            'price' => 85000.00, // Box price 85000 is cheaper than 12 x 8000 = 96000
        ]);

        $resellerCustomer = Customer::create([
            'customer_name' => 'Pelanggan Reseller',
            'customer_email' => 'reseller@example.com',
            'customer_phone' => '08123456789',
            'tier' => 'RESELLER',
        ]);

        $wholesalerCustomer = Customer::create([
            'customer_name' => 'Pelanggan Wholesaler',
            'customer_email' => 'wholesaler@example.com',
            'customer_phone' => '08123456790',
            'tier' => 'WHOLESALER',
        ]);

        // 1. Add 1 base unit (non-tier) -> base price 8000
        $response1 = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $product->id,
                'qty' => 1,
            ]);

        $response1->assertOk();
        $lineId = array_key_first($response1->json('cart_snapshot.lines'));

        // 2. Add 11 more units (total qty 12) -> non-tier conversion box total 85000 Rupiah
        $response2 = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->postJson(route('pos.sell.cart.lines.store'), [
                'product_id' => $product->id,
                'qty' => 11,
            ]);

        $response2->assertOk();
        $line2 = $response2->json("cart_snapshot.lines.{$lineId}");
        $this->assertEquals(85000, $line2['line_total']);
        $this->assertEquals('PACKED', $line2['price_source']);

        // 3. Select Reseller customer -> line total becomes 12 x 6583.33 = 78999.96 Rupiah
        $response3 = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), [
                'customer_id' => $resellerCustomer->id,
            ]);

        $response3->assertOk();
        $line3 = $response3->json("cart_snapshot.lines.{$lineId}");
        $this->assertEquals(78999.96, $line3['line_total']);
        $this->assertEquals('PACKED', $line3['price_source']);
        $this->assertFalse($line3['breakdown']['is_box_cheaper']);

        // 4. Change customer to Wholesaler -> line total becomes 12 x 7500 = 90000 Rupiah
        $response4 = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), [
                'customer_id' => $wholesalerCustomer->id,
            ]);

        $response4->assertOk();
        $line4 = $response4->json("cart_snapshot.lines.{$lineId}");
        $this->assertEquals(90000, $line4['line_total']);
        $this->assertFalse($line4['breakdown']['is_box_cheaper']);

        // 5. Clear selected customer back to non-tier -> restores conversion box price 85000 Rupiah
        $response5 = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->patchJson(route('pos.sell.cart.customer.update'), [
                'customer_id' => null,
            ]);

        $response5->assertOk();
        $line5 = $response5->json("cart_snapshot.lines.{$lineId}");
        $this->assertEquals(85000, $line5['line_total']);
        $this->assertTrue($line5['breakdown']['is_box_cheaper']);
    }

    /**
     * Task 4.4: Formatting coverage verifying 78999.96 is rendered with two decimal places.
     */
    public function test_sell_view_formatting_contains_two_decimal_places_for_fractional_totals(): void
    {
        $setting = $this->createSetting('SELL-VIEW-FORMAT-SETTING');
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'CASHIER-VIEW');

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->get(route('pos.sell'));

        $response->assertOk();
        $content = $response->getContent();

        // 1. Assert view contains the two fraction-digit options and formatPrice declaration
        $response->assertSee('minimumFractionDigits: 2');
        $response->assertSee('maximumFractionDigits: 2');
        $response->assertSee('function formatPrice');

        // 2. Parse the exact idrFormatter block dynamically from the rendered sell view HTML/JS
        preg_match('/const idrFormatter = new Intl\.NumberFormat\(\s*\'id-ID\'\s*,\s*(\{[\s\S]*?\})\s*\);/', $content, $matches);
        $this->assertNotEmpty($matches[1] ?? null, 'Failed to extract idrFormatter options from sell.blade.php view output');

        $optionsJsonStr = $matches[1];

        // 3. Execute the extracted formatter configuration directly in Node.js to prove output formatting
        $nodeScript = sprintf(
            'const opts = %s; const f = new Intl.NumberFormat("id-ID", opts); console.log(f.format(78999.96));',
            $optionsJsonStr
        );

        $process = new \Symfony\Component\Process\Process(['node', '-e', $nodeScript]);
        $process->run();

        $this->assertTrue($process->isSuccessful(), 'Node execution of extracted JS idrFormatter failed');
        $output = trim($process->getOutput());
        $this->assertStringContainsString('78.999,96', $output);
    }

    // --- Helpers ---

    private function createSetting(string $name): Setting
    {
        return Setting::create([
            'company_name' => $name,
            'company_email' => strtolower(str_replace([' ', '-'], '.', $name)) . '@example.com',
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
            'name' => 'TIER LOC ' . $sequence,
            'setting_id' => $setting->id,
        ]);

        SalesLocationResolver::forget($setting->id);

        $terminal = PosTerminal::create([
            'setting_id' => $setting->id,
            'code' => 'POS-TIER-' . str_pad((string) $sequence, 2, '0', STR_PAD_LEFT),
            'name' => 'POS Tier Terminal ' . $sequence,
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

    private function createStockedProductWithTiers(
        Setting $setting,
        Location $location,
        string $code,
        string $name,
        float $salePrice,
        float $tier1Price,
        float $tier2Price,
        int $createdBy
    ): Product {
        $category = Category::firstOrCreate(
            ['category_code' => 'TIER-CAT-' . $setting->id],
            [
                'category_name' => 'Tier Category ' . $setting->id,
                'created_by' => $createdBy,
                'setting_id' => $setting->id,
            ]
        );

        $unit = Unit::firstOrCreate([
            'name' => 'PieceTier',
            'short_name' => 'PCST',
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
            'product_unit' => 'PCST',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => false,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'sale_price' => $salePrice,
            'tier_1_price' => $tier1Price,
            'tier_2_price' => $tier2Price,
        ]);

        ProductStock::create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 1000,
            'quantity_non_tax' => 1000,
            'quantity_tax' => 0,
            'broken_quantity_non_tax' => 0,
            'broken_quantity_tax' => 0,
            'broken_quantity' => 0,
        ]);

        return $product;
    }
}
