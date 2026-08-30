<?php

namespace Modules\Pos\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Pos\Services\PosCartService;
use Modules\Pos\Services\PosCartSessionStore;
use Tests\TestCase;

/**
 * Canonical override metadata must be refreshed or removed whenever the row it
 * describes changes underneath it.
 *
 * A unit-price override stays authoritative (the cashier set a price, so it
 * should hold at the new quantity) and its metadata is recomputed. A row-total
 * override cannot survive (the approved total was for the old row) so the line
 * reverts to standard pricing with every canonical field removed.
 */
class PosCartOverrideMetadataRefreshTest extends TestCase
{
    use RefreshDatabase;

    private const CANONICAL_FIELDS = [
        'line_total',
        'line_gross_minor',
        'line_discount_minor',
        'line_net_minor',
        'line_tax_minor',
        'line_taxable_base_minor',
    ];

    private PosCartService $cartService;
    private PosCartSessionStore $store;
    private User $user;
    private int $settingId;
    private int $sessionId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::store()->flush();

        $this->cartService = app(PosCartService::class);
        $this->store = app(PosCartSessionStore::class);
        $this->user = User::factory()->create();

        // Direct override authority: these tests exercise metadata refresh, not
        // the approval lifecycle, which is covered elsewhere.
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['pos.access', 'pos.sell', 'pos.overrides.price'] as $permission) {
            \Spatie\Permission\Models\Permission::findOrCreate($permission, 'web');
        }
        $this->user->givePermissionTo(['pos.access', 'pos.sell', 'pos.overrides.price']);

        // Category carries a created_by audit column.
        $this->actingAs($this->user);

        [$this->settingId, $this->sessionId, $this->productId] = $this->createPosContext();
    }

    /**
     * @return array{0:int, 1:int, 2:int}
     */
    private function createPosContext(): array
    {
        \Modules\Currency\Entities\Currency::create([
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $setting = \Modules\Setting\Entities\Setting::create([
            'company_name' => 'BIZ OVERRIDE METADATA',
            'company_email' => 'metadata@example.com',
            'company_phone' => '0800000000',
            'company_address' => 'Address',
            'default_currency_id' => \Modules\Currency\Entities\Currency::query()->value('id'),
            'default_currency_position' => 'prefix',
            'notification_email' => 'notify@example.com',
            'footer_text' => 'Footer',
            'document_prefix' => 'DOC',
            'purchase_prefix_document' => 'PO',
            'sale_prefix_document' => 'SO',
            'pos_enabled' => true,
            'is_pkp' => false,
        ]);

        $location = \Modules\Setting\Entities\Location::create([
            'name' => 'METADATA LOC',
            'setting_id' => $setting->id,
        ]);

        $terminal = \Modules\Pos\Entities\PosTerminal::create([
            'setting_id' => $setting->id,
            'location_id' => $location->id,
            'name' => 'METADATA TERMINAL',
            'code' => 'MTD-1',
            'is_active' => true,
        ]);

        $session = \Modules\Pos\Entities\PosSession::create([
            'setting_id' => $setting->id,
            'terminal_id' => $terminal->id,
            'cashier_user_id' => $this->user->id,
            'status' => \Modules\Pos\Entities\PosSession::STATUS_OPEN,
            'opened_at' => now(),
            'opened_by' => $this->user->id,
            'opening_float_total' => 100000,
            'expected_cash_total' => 100000,
            'active_marker' => 1,
        ]);

        $category = \Modules\Product\Entities\Category::create([
            'category_code' => 'MTD',
            'category_name' => 'METADATA',
            'created_by' => $this->user->id,
            'setting_id' => $setting->id,
        ]);

        $unit = \Modules\Setting\Entities\Unit::create(['name' => 'UNIT', 'short_name' => 'U']);

        $product = \Modules\Product\Entities\Product::create([
            'setting_id' => $setting->id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'product_name' => 'PRODUK METADATA',
            'product_code' => 'MTD-001',
            'barcode' => 'MTD-001-BAR',
            'product_quantity' => 100,
            'product_cost' => 5000,
            'product_price' => 10000,
            'product_unit' => 'U',
            'product_stock_alert' => 1,
            'stock_managed' => false,
            'is_sold' => true,
            'serial_number_required' => false,
        ]);

        \Modules\Product\Entities\ProductPrice::create([
            'product_id' => $product->id,
            'setting_id' => $setting->id,
            'sale_price' => 10000,
            'tier_1_price' => null,
            'tier_2_price' => null,
            'last_purchase_price' => 5000,
            'average_purchase_price' => 5000,
        ]);

        return [(int) $setting->id, (int) $session->id, (int) $product->id];
    }

    private function seedLine(int $qty = 2): int
    {
        $snapshot = $this->cartService->addLine($this->settingId, $this->sessionId, $this->productId, $qty);

        return (int) $snapshot['lines'][0]['line_id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function storedLine(int $lineId): array
    {
        return $this->store->getCart($this->settingId, $this->sessionId)['lines'][$lineId];
    }

    private function assertNoCanonicalMetadata(array $line, string $context): void
    {
        foreach (self::CANONICAL_FIELDS as $field) {
            $this->assertArrayNotHasKey(
                $field,
                $line,
                "Stale canonical field {$field} survived {$context}."
            );
        }
    }

    private function applyUnitPriceOverride(int $lineId, float $unitPrice): void
    {
        $this->cartService->overrideLineUnitPrice(
            $this->settingId,
            $this->sessionId,
            (int) $this->user->id,
            $lineId,
            $unitPrice,
            'test',
            null,
            $this->user
        );
    }

    private function applyRowTotalOverride(int $lineId, float $rowTotal): void
    {
        $this->cartService->overrideLineTotal(
            $this->settingId,
            $this->sessionId,
            (int) $this->user->id,
            $lineId,
            $rowTotal,
            'test',
            null,
            $this->user
        );
    }

    // ------------------------------------------------ quantity changes

    public function test_unit_price_override_metadata_is_refreshed_after_a_quantity_increase(): void
    {
        $lineId = $this->seedLine(2);
        $this->applyUnitPriceOverride($lineId, 10000.0);

        $this->assertSame(2_000_000, $this->storedLine($lineId)['line_net_minor']);

        $this->cartService->updateLine($this->settingId, $this->sessionId, $lineId, ['qty' => 3]);

        $line = $this->storedLine($lineId);

        $this->assertSame('LINE_UNIT_PRICE_OVERRIDE', $line['price_source'], 'The authoritative price was lost.');
        $this->assertSame(10000.0, (float) $line['unit_price']);
        $this->assertSame(
            3_000_000,
            $line['line_net_minor'],
            'Metadata still reports the pre-change quantity.'
        );
        $this->assertSame(3_000_000, $line['line_gross_minor']);
    }

    public function test_row_total_override_is_invalidated_after_a_quantity_change(): void
    {
        $lineId = $this->seedLine(2);
        $this->applyRowTotalOverride($lineId, 15000.0);

        $this->assertSame('LINE_TOTAL_OVERRIDE', $this->storedLine($lineId)['price_source']);

        $this->cartService->updateLine($this->settingId, $this->sessionId, $lineId, ['qty' => 3]);

        $line = $this->storedLine($lineId);

        $this->assertNotSame('LINE_TOTAL_OVERRIDE', $line['price_source']);
        $this->assertNoCanonicalMetadata($line, 'a quantity change on a row-total override');
    }

    // ------------------------------------------------ discount changes

    public function test_unit_price_override_metadata_is_refreshed_after_a_fixed_discount_change(): void
    {
        $lineId = $this->seedLine(2);
        $this->applyUnitPriceOverride($lineId, 10000.0);

        $this->cartService->updateLine($this->settingId, $this->sessionId, $lineId, [
            'line_discount_type' => 'fixed',
            'line_discount_value' => 5000,
        ]);

        $line = $this->storedLine($lineId);

        $this->assertSame('LINE_UNIT_PRICE_OVERRIDE', $line['price_source']);
        $this->assertSame(2_000_000, $line['line_gross_minor']);
        $this->assertSame(500_000, $line['line_discount_minor']);
        $this->assertSame(1_500_000, $line['line_net_minor']);
    }

    public function test_unit_price_override_metadata_is_refreshed_after_a_percentage_discount_change(): void
    {
        $lineId = $this->seedLine(2);
        $this->applyUnitPriceOverride($lineId, 10000.0);

        $this->cartService->updateLine($this->settingId, $this->sessionId, $lineId, [
            'line_discount_type' => 'percentage',
            'line_discount_value' => 10,
        ]);

        $line = $this->storedLine($lineId);

        $this->assertSame(2_000_000, $line['line_gross_minor']);
        $this->assertSame(200_000, $line['line_discount_minor']);
        $this->assertSame(1_800_000, $line['line_net_minor']);
    }

    // --------------------------------------------- customer repricing

    public function test_customer_repricing_clears_unit_price_override_metadata(): void
    {
        $lineId = $this->seedLine(2);
        $this->applyUnitPriceOverride($lineId, 4500.0);

        $customer = \Modules\People\Entities\Customer::create([
            'setting_id' => $this->settingId,
            'customer_name' => 'PELANGGAN TIER',
            'customer_email' => 'tier@example.com',
            'customer_phone' => '0811',
            'address' => '',
            'city' => '',
            'country' => '',
        ]);

        $this->cartService->updateCustomerSelection($this->settingId, $this->sessionId, (int) $customer->id);

        $line = $this->storedLine($lineId);

        $this->assertNotEquals('LINE_UNIT_PRICE_OVERRIDE', $line['price_source'] ?? '');
        $this->assertNoCanonicalMetadata($line, 'customer repricing away from a unit-price override');
    }

    public function test_customer_repricing_clears_row_total_override_metadata(): void
    {
        $lineId = $this->seedLine(2);
        $this->applyRowTotalOverride($lineId, 15000.0);

        $customer = \Modules\People\Entities\Customer::create([
            'setting_id' => $this->settingId,
            'customer_name' => 'PELANGGAN TIER 2',
            'customer_email' => 'tier2@example.com',
            'customer_phone' => '0812',
            'address' => '',
            'city' => '',
            'country' => '',
        ]);

        $this->cartService->updateCustomerSelection($this->settingId, $this->sessionId, (int) $customer->id);

        $line = $this->storedLine($lineId);

        $this->assertNotEquals('LINE_TOTAL_OVERRIDE', $line['price_source'] ?? '');
        $this->assertNoCanonicalMetadata($line, 'customer repricing away from a row-total override');
    }

    // ------------------------------------------------- serial append

    public function test_quantity_increment_from_serial_append_refreshes_metadata(): void
    {
        // appendSerial() auto-increments qty when the line is already full.
        // Building a serial-tracked, location-stocked product here would test
        // the serial fixture rather than the refresh rule, so this drives the
        // same code path through its shared helper: increment the quantity on a
        // stored line, then assert the metadata followed.
        $lineId = $this->seedLine(1);
        $this->applyUnitPriceOverride($lineId, 10000.0);

        $this->assertSame(1_000_000, $this->storedLine($lineId)['line_net_minor']);

        $cart = $this->store->getCart($this->settingId, $this->sessionId);
        $cart['lines'][$lineId]['qty'] = 2;

        $refreshed = (new \ReflectionMethod($this->cartService, 'refreshOrInvalidateRowOverride'))
            ->invoke($this->cartService, $this->settingId, $cart['lines'][$lineId], $cart);

        $this->assertSame('LINE_UNIT_PRICE_OVERRIDE', $refreshed['price_source']);
        $this->assertSame(
            2_000_000,
            $refreshed['line_net_minor'],
            'Metadata was not refreshed after the quantity increment.'
        );
    }

    public function test_row_total_override_is_invalidated_by_a_quantity_increment(): void
    {
        $lineId = $this->seedLine(1);
        $this->applyRowTotalOverride($lineId, 9000.0);

        $cart = $this->store->getCart($this->settingId, $this->sessionId);
        $cart['lines'][$lineId]['qty'] = 2;

        $refreshed = (new \ReflectionMethod($this->cartService, 'refreshOrInvalidateRowOverride'))
            ->invoke($this->cartService, $this->settingId, $cart['lines'][$lineId], $cart);

        $this->assertNotSame('LINE_TOTAL_OVERRIDE', $refreshed['price_source']);
        $this->assertNoCanonicalMetadata($refreshed, 'a quantity increment on a row-total override');
    }

    // -------------------------------------- standard-price restoration

    /**
     * Drive the restoration resolver for one line shape.
     *
     * @param  array<string, mixed>  $lineOverrides
     * @param  array<string, mixed>  $cartOverrides
     * @return array<string, mixed>
     */
    private function restoreAfterRowTotalOverride(array $lineOverrides, array $cartOverrides = []): array
    {
        $cart = array_merge(
            $this->store->getCart($this->settingId, $this->sessionId),
            $cartOverrides
        );

        $line = array_merge([
            'line_id' => 99,
            'product_id' => $this->productId,
            'qty' => 2,
            'unit_price' => 7500.0,
            'price_source' => 'LINE_TOTAL_OVERRIDE',
            'line_discount_type' => 'fixed',
            'line_discount_value' => 0.0,
            'tax_id' => null,
            'tax_rate' => 0.0,
            // Canonical metadata that must not survive restoration.
            'line_total' => 1_500_000,
            'line_gross_minor' => 1_500_000,
            'line_discount_minor' => 0,
            'line_net_minor' => 1_500_000,
            'line_tax_minor' => 0,
            'line_taxable_base_minor' => 1_500_000,
        ], $lineOverrides);

        return (new \ReflectionMethod($this->cartService, 'restoreStandardPricing'))
            ->invoke($this->cartService, $this->settingId, $line, $cart);
    }

    public function test_row_total_invalidation_restores_a_bundle_parent_to_its_bundle_price(): void
    {
        $bundle = \Modules\Product\Entities\ProductBundle::create([
            'setting_id' => $this->settingId,
            'parent_product_id' => $this->productId,
            'name' => 'PAKET UJI',
            'price' => 2000,
            'bundle_sale_price' => 25000,
            'is_active' => true,
        ]);

        $componentSnapshot = [[
            'bundle_item_id' => 1,
            'product_id' => $this->productId,
            'product_name' => 'KOMPONEN',
            'quantity_per_bundle' => 1.0,
            'quantity' => 1.0,
            'stock_managed' => false,
            'serial_number_required' => false,
            'informational_item_price' => 12500.0,
        ]];

        $restored = $this->restoreAfterRowTotalOverride([
            'bundle_id' => $bundle->id,
            'bundle_name' => 'PAKET UJI',
            'bundle_items' => $componentSnapshot,
        ]);

        $this->assertSame(
            'BUNDLE',
            $restored['price_source'],
            'A bundle parent must not be restored as an ordinary row.'
        );
        $this->assertSame(
            25000.0,
            (float) $restored['unit_price'],
            'The bundle sale price is authoritative, not the parent product price.'
        );

        // Bundle identity and component snapshots survive untouched.
        $this->assertSame($bundle->id, $restored['bundle_id']);
        $this->assertSame('PAKET UJI', $restored['bundle_name']);
        $this->assertSame($componentSnapshot, $restored['bundle_items']);
        $this->assertSame(
            12500.0,
            (float) $restored['bundle_items'][0]['informational_item_price'],
            'Informational component allocations must be preserved.'
        );

        $this->assertNoCanonicalMetadata($restored, 'bundle restoration');
    }

    public function test_row_total_invalidation_repacks_a_packed_row(): void
    {
        // Shape produced by buildPricingBasis(); prices are minor units.
        $pricingBasis = [
            'factor' => 12,
            'box_price' => 10_000_000,
            'base_price' => 1_000_000,
            'tier_1_price' => 0,
            'tier_2_price' => 0,
            'tax_id' => null,
            'tax_name' => null,
            'tax_rate' => 0.0,
            'conversion_unit_label' => 'BOX',
            'base_unit_label' => 'PCS',
        ];

        $restored = $this->restoreAfterRowTotalOverride([
            'qty' => 12,
            'pricing_basis' => $pricingBasis,
        ]);

        $this->assertSame(
            'PACKED',
            $restored['price_source'],
            'A packed row must be re-packed, not flattened to a unit price.'
        );
        $this->assertArrayHasKey('breakdown', $restored);
        $this->assertGreaterThan(0, (float) $restored['unit_price']);
    }

    public function test_row_total_invalidation_restores_an_ordinary_row_to_base(): void
    {
        $restored = $this->restoreAfterRowTotalOverride([]);

        $this->assertSame('BASE', $restored['price_source']);
        $this->assertSame(10000.0, (float) $restored['unit_price']);
        $this->assertNoCanonicalMetadata($restored, 'ordinary row restoration');
    }

    public function test_row_total_invalidation_restores_a_tier_priced_row_as_tier(): void
    {
        $customer = \Modules\People\Entities\Customer::create([
            'setting_id' => $this->settingId,
            'customer_name' => 'PELANGGAN GROSIR',
            'customer_email' => 'grosir@example.com',
            'customer_phone' => '0813',
            'address' => '',
            'city' => '',
            'country' => '',
            'tier' => 'WHOLESALER',
        ]);

        $restored = $this->restoreAfterRowTotalOverride([], [
            'selected_customer_id' => (int) $customer->id,
            'selected_customer_tier' => 'WHOLESALER',
        ]);

        $this->assertSame(
            'TIER',
            $restored['price_source'],
            'A tier-priced row must be restored under its tier source.'
        );
        $this->assertNoCanonicalMetadata($restored, 'tier row restoration');
    }

    // ------------------------------------------------------- snapshot

    public function test_snapshot_carries_no_stale_metadata_after_a_quantity_change(): void
    {
        $lineId = $this->seedLine(2);
        $this->applyRowTotalOverride($lineId, 15000.0);

        $snapshot = $this->cartService->updateLine(
            $this->settingId,
            $this->sessionId,
            $lineId,
            ['qty' => 4]
        );

        $line = collect($snapshot['lines'])->firstWhere('line_id', $lineId);

        // The calculator writes its own line_total onto snapshot output, so the
        // meaningful assertion is that it reflects the new quantity rather than
        // the invalidated override.
        $this->assertNotSame('LINE_TOTAL_OVERRIDE', $line['price_source']);
        $this->assertSame(
            40000.0,
            (float) $line['line_net_before_bill'],
            'Snapshot reported a stale overridden total after the quantity changed.'
        );
    }
}
