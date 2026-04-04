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
use Modules\Product\Entities\ProductSerialNumber;
use Modules\Product\Entities\ProductStock;
use Modules\Product\Entities\ProductUnitConversion;
use Modules\Purchase\Entities\PaymentTerm;
use Modules\Sale\Entities\Dispatch;
use Modules\Sale\Entities\DispatchDetail;
use Modules\Sale\Entities\Sale;
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
            ->assertJsonPath('product.product_name', strtoupper('Produk Scan'));
    }

    public function test_exact_product_sku_returns_none(): void
    {
        $setting = $this->createSetting('SCAN RESOLVE SKU');
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'SCAN RESOLVE SKU');

        $product = $this->createStockedProduct($setting, $location, 'SKU-SCAN-002', 'Produk SKU', 75000, $cashier->id);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.search.resolve', ['q' => 'SKU-SCAN-002']));

        // Phase 4: SKU scanning is no longer supported in scan resolver
        $response->assertOk()
            ->assertJsonPath('type', 'none');
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

    public function test_conversion_barcode_returns_product_exact(): void
    {
        $setting = $this->createSetting('SCAN RESOLVE CONVERSION BARCODE');
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'SCAN RESOLVE CONVERSION BARCODE');

        $product = $this->createStockedProduct($setting, $location, 'SKU-CONV-001', 'Produk Konversi', 50000, $cashier->id);

        // Create a unit conversion with a barcode
        $unit = Unit::firstOrCreate([
            'name' => 'Pack',
            'short_name' => 'PKG',
        ]);

        $conversionBarcode = 'CONV-BC-' . uniqid();
        ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $product->base_unit_id,
            'conversion_factor' => 12,
            'barcode' => $conversionBarcode,
        ]);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.search.resolve', ['q' => $conversionBarcode]));

        $response->assertOk()
            ->assertJsonPath('type', 'product_exact')
            ->assertJsonPath('product.id', $product->id)
            ->assertJsonPath('product.product_name', strtoupper('Produk Konversi'))
            ->assertJsonPath('product.resolved_via', 'conversion_barcode')
            ->assertJsonPath('product.conversion.conversion_factor', 12)
            ->assertJsonPath('product.conversion.unit_name', strtoupper('Pack'));
    }

    /**
     * Regression test for Phase 1: conversion barcode scan should not throw SQL 42S22
     * (Unknown column 'setting_id' in 'where clause'). This guards against
     * the bug where ProductUnitConversion query incorrectly applied setting_id filter.
     */
    public function test_conversion_barcode_scan_does_not_error_on_setting_id(): void
    {
        $setting = $this->createSetting('SCAN RESOLVE CONVERSION NO ERROR');
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'SCAN RESOLVE CONVERSION NO ERROR');

        $product = $this->createStockedProduct($setting, $location, 'SKU-CONV-002', 'Produk Scan Conversion', 75000, $cashier->id);

        $unit = Unit::firstOrCreate([
            'name' => 'Carton',
            'short_name' => 'CTN',
        ]);

        $conversionBarcode = 'CONV-BC-' . uniqid();
        ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $product->base_unit_id,
            'conversion_factor' => 24,
            'barcode' => $conversionBarcode,
        ]);

        // Scan should return 200 (not 500 SQL error before the fix)
        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.search.resolve', ['q' => $conversionBarcode]));

        $response->assertOk();
        // Verify response structure is valid (not an error payload)
        $this->assertIsString($response->json('type'));
    }

    /**
     * Phase 5: Serial number scan should return serial_exact type with serial metadata.
     * This tests the third resolution branch in PosScanResolverService::resolve().
     */
    public function test_serial_number_scan_returns_serial_exact(): void
    {
        $setting = $this->createSetting('SCAN RESOLVE SERIAL');
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'SCAN RESOLVE SERIAL');

        $product = $this->createStockedProduct(
            $setting,
            $location,
            'SKU-SERIAL-001',
            'Produk Serial',
            100000,
            $cashier->id
        );

        $serialNumber = 'SN-' . time() . '-' . rand(1000, 9999);
        ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => $serialNumber,
            'status' => 'ACTIVE',
            'location_id' => $location->id,
            'tax_id' => null,
        ]);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.search.resolve', ['q' => $serialNumber]));

        $response->assertOk()
            ->assertJsonPath('type', 'serial_exact')
            ->assertJsonPath('serial.serial_number', $serialNumber)
            ->assertJsonPath('serial.product_id', $product->id)
            ->assertJsonPath('serial.location_id', $location->id)
            ->assertJsonPath('product.id', $product->id)
            ->assertJsonPath('product.serial_number_required', false);
    }

    public function test_serial_number_scan_returns_none_when_serial_is_in_pending_dispatch(): void
    {
        $setting = $this->createSetting('SCAN RESOLVE SERIAL PENDING');
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'SCAN RESOLVE SERIAL PENDING');

        $product = $this->createStockedProduct(
            $setting,
            $location,
            'SKU-SERIAL-PENDING',
            'Produk Serial Pending',
            100000,
            $cashier->id
        );

        $serialNumber = 'SN-PENDING-' . time() . '-' . rand(1000, 9999);
        ProductSerialNumber::create([
            'product_id' => $product->id,
            'serial_number' => $serialNumber,
            'status' => 'ACTIVE',
            'location_id' => $location->id,
            'tax_id' => null,
        ]);

        $this->createDispatchForSerial($setting, $product, $location, $serialNumber);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.search.resolve', ['q' => $serialNumber]));

        $response->assertOk()
            ->assertJsonPath('type', 'none');
    }

    /**
     * Phase 5: Barcode resolution should be case-insensitive.
     * Service uses LOWER(barcode) for matching.
     */
    public function test_barcode_resolution_is_case_insensitive(): void
    {
        $setting = $this->createSetting('SCAN RESOLVE CASE INSENSITIVE');
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'SCAN RESOLVE CASE INSENSITIVE');

        $product = $this->createStockedProduct(
            $setting,
            $location,
            'SKU-CASE-001',
            'Produk Case Test',
            45000,
            $cashier->id
        );

        // Barcode is ABC-123, scan with lowercase
        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.search.resolve', ['q' => strtolower($product->barcode)]));

        $response->assertOk()
            ->assertJsonPath('type', 'product_exact')
            ->assertJsonPath('product.id', $product->id);
    }

    /**
     * Phase 5: Product barcode response should include resolved_via=product_barcode
     * and conversion=null.
     */
    public function test_product_barcode_response_includes_resolved_via_product_barcode(): void
    {
        $setting = $this->createSetting('SCAN RESOLVE RESOLVED_VIA');
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'SCAN RESOLVE RESOLVED_VIA');

        $product = $this->createStockedProduct(
            $setting,
            $location,
            'SKU-RESOLVED-001',
            'Produk Resolved Via',
            62000,
            $cashier->id
        );

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.search.resolve', ['q' => $product->barcode]));

        $response->assertOk()
            ->assertJsonPath('type', 'product_exact')
            ->assertJsonPath('product.resolved_via', 'product_barcode')
            ->assertJsonPath('product.conversion', null);
    }

    /**
     * Phase 5: Product without stock in allowed locations should return none.
     * This tests the hasStockInAllowedLocations guard in PosScanResolverService.
     */
    public function test_product_without_stock_in_allowed_locations_returns_none(): void
    {
        $setting = $this->createSetting('SCAN RESOLVE NO STOCK');
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'SCAN RESOLVE NO STOCK');

        // Create category and product manually without stock
        $category = Category::firstOrCreate(
            ['category_code' => 'SCAN-NO-STOCK-CAT'],
            [
                'category_name' => 'Scan No Stock Category',
                'created_by' => $cashier->id,
                'setting_id' => $setting->id,
            ]
        );

        $unit = Unit::firstOrCreate(['name' => 'Piece', 'short_name' => 'PCS']);

        $product = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => 'Produk No Stock',
            'product_code' => 'SKU-NOSTOCK-001',
            'barcode' => 'BC-NOSTOCK-' . uniqid(),
            'product_quantity' => 0,
            'product_cost' => 10000,
            'product_price' => 50000,
            'product_unit' => 'PCS',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => false,
        ]);

        // Create price but NO stock
        ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'sale_price' => 50000,
        ]);

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.search.resolve', ['q' => $product->barcode]));

        $response->assertOk()
            ->assertJsonPath('type', 'none');
    }

    /**
     * Phase 5: Product without price for setting should return none.
     * This tests the hasPriceForSetting guard in PosScanResolverService.
     */
    public function test_product_without_price_for_setting_returns_none(): void
    {
        $setting = $this->createSetting('SCAN RESOLVE NO PRICE');
        [$cashier, $location] = $this->createCashierAndOpenSession($setting, 'SCAN RESOLVE NO PRICE');

        // Create category and product
        $category = Category::firstOrCreate(
            ['category_code' => 'SCAN-NO-PRICE-CAT'],
            [
                'category_name' => 'Scan No Price Category',
                'created_by' => $cashier->id,
                'setting_id' => $setting->id,
            ]
        );

        $unit = Unit::firstOrCreate(['name' => 'Piece', 'short_name' => 'PCS']);

        $product = Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => 'Produk No Price',
            'product_code' => 'SKU-NOPRICE-001',
            'barcode' => 'BC-NOPRICE-' . uniqid(),
            'product_quantity' => 100,
            'product_cost' => 15000,
            'product_price' => 55000,
            'product_unit' => 'PCS',
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => false,
        ]);

        // Create stock but NO price row
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

        $response = $this->actingAs($cashier)
            ->withSession(['setting_id' => $setting->id])
            ->getJson(route('pos.sell.search.resolve', ['q' => $product->barcode]));

        $response->assertOk()
            ->assertJsonPath('type', 'none');
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

    private function createDispatchForSerial(
        Setting $setting,
        Product $product,
        Location $location,
        string $serialNumber,
        string $status = Dispatch::STATUS_PENDING
    ): DispatchDetail {
        $paymentTerm = PaymentTerm::query()->firstOrCreate(
            ['name' => 'POS SCAN TERM'],
            ['longevity' => 0]
        );

        $customer = Customer::factory()->create([
            'setting_id' => $setting->id,
            'payment_term_id' => $paymentTerm->id,
        ]);

        $sale = Sale::query()->create([
            'date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name,
            'tax_percentage' => 0,
            'tax_amount' => 0,
            'discount_percentage' => 0,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'due_amount' => 100000,
            'status' => 'Approved',
            'payment_status' => 'Unpaid',
            'payment_method' => 'cash',
            'payment_term_id' => $paymentTerm->id,
            'setting_id' => $setting->id,
            'is_tax_included' => false,
            'reference' => 'POS-SCAN-DSP-' . uniqid(),
        ]);

        $dispatch = Dispatch::query()->create([
            'sale_id' => $sale->id,
            'dispatch_date' => now()->toDateString(),
            'status' => $status,
        ]);

        return DispatchDetail::query()->create([
            'dispatch_id' => $dispatch->id,
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'dispatched_quantity' => 1,
            'location_id' => $location->id,
            'tax_id' => null,
            'serial_numbers' => json_encode([$serialNumber]),
        ]);
    }
}
