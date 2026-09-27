<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Services\CountDraftService;
use Modules\Currency\Entities\Currency;
use Modules\Product\Entities\Product;
use Modules\Product\Entities\ProductStock;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

/**
 * Verify count-draft schema version 2: canonical fingerprints,
 * per-location baseline structures, v1 backward-compatible reads,
 * and validation of multi-location pools.
 */
class CountDraftSchemaVersionTest extends TestCase
{
    use RefreshDatabase;

    private CountDraftService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CountDraftService::class);
    }

    // ------------------------------------------------------------------
    //  Helpers
    // ------------------------------------------------------------------

    private function makeSetting(array $overrides = []): Setting
    {
        $currency = Currency::firstOrCreate(
            ['code' => 'IDR'],
            [
                'currency_name' => 'Rupiah',
                'symbol' => 'RP',
                'thousand_separator' => '.',
                'decimal_separator' => ',',
                'exchange_rate' => 1,
            ]
        );

        return Setting::create(array_merge([
            'company_name' => 'Test Company ' . uniqid(),
            'company_email' => 'test@test.test',
            'company_phone' => '0800000000',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'test@test.test',
            'footer_text' => 'Footer',
            'company_address' => 'Bandung',
            'is_pkp' => false,
        ], $overrides));
    }

    private function makeLocation(Setting $setting, array $overrides = []): Location
    {
        return Location::create(array_merge([
            'setting_id' => $setting->id,
            'name' => 'Gudang ' . uniqid(),
        ], $overrides));
    }

    private function makeProduct(?Setting $setting = null, array $overrides = []): Product
    {
        $settingId = $setting ? $setting->id : (Setting::first()?->id ?? $this->makeSetting()->id);

        return Product::create(array_merge([
            'setting_id' => $settingId,
            'product_name' => 'Product ' . uniqid(),
            'product_code' => 'P' . uniqid(),
            'barcode' => 'BC' . uniqid(),
            'product_cost' => 1000,
            'product_price' => 2000,
            'product_quantity' => 10,
            'product_stock_alert' => 1,
            'stock_managed' => true,
            'serial_number_required' => false,
        ], $overrides));
    }

    private function makeProductStock(Product $product, Location $location, array $overrides = []): ProductStock
    {
        return ProductStock::create(array_merge([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 10,
            'quantity_tax' => 0,
            'quantity_non_tax' => 10,
            'broken_quantity' => 2,
            'broken_quantity_tax' => 0,
            'broken_quantity_non_tax' => 2,
        ], $overrides));
    }

    private function actingAsUser(): \App\Models\User
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);
        return $user;
    }

    // ------------------------------------------------------------------
    //  Fingerprint tests
    // ------------------------------------------------------------------

    /** @test */
    public function fingerprint_is_deterministic_for_same_ids(): void
    {
        $fp1 = CountDraftService::locationSetFingerprint([3, 1, 2]);
        $fp2 = CountDraftService::locationSetFingerprint([1, 2, 3]);
        $fp3 = CountDraftService::locationSetFingerprint([2, 3, 1]);

        $this->assertEquals($fp1, $fp2);
        $this->assertEquals($fp2, $fp3);
    }

    /** @test */
    public function fingerprint_differs_for_different_id_sets(): void
    {
        $fp1 = CountDraftService::locationSetFingerprint([1, 2]);
        $fp2 = CountDraftService::locationSetFingerprint([1, 3]);

        $this->assertNotEquals($fp1, $fp2);
    }

    /** @test */
    public function fingerprint_removes_duplicates(): void
    {
        $fp1 = CountDraftService::locationSetFingerprint([1, 2, 2, 3]);
        $fp2 = CountDraftService::locationSetFingerprint([1, 2, 3]);

        $this->assertEquals($fp1, $fp2);
    }

    /** @test */
    public function fingerprint_is_sha256(): void
    {
        $fp = CountDraftService::locationSetFingerprint([1, 2]);

        $this->assertEquals(64, strlen($fp)); // SHA-256 = 64 hex chars
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fp);
    }

    // ------------------------------------------------------------------
    //  Schema version constants
    // ------------------------------------------------------------------

    /** @test */
    public function schema_version_constants_are_correct(): void
    {
        $this->assertEquals(1, CountDraftService::SCHEMA_VERSION);
        $this->assertEquals(2, CountDraftService::SCHEMA_VERSION_2);
    }

    // ------------------------------------------------------------------
    //  V2 structure tests
    // ------------------------------------------------------------------

    /** @test */
    public function v2_draft_has_canonical_structure(): void
    {
        $this->actingAsUser();
        $setting = $this->makeSetting();
        $locA = $this->makeLocation($setting, ['name' => 'Gudang A']);
        $locB = $this->makeLocation($setting, ['name' => 'Gudang B']);
        $product = $this->makeProduct();

        $this->makeProductStock($product, $locA, ['quantity' => 5, 'quantity_non_tax' => 5]);
        $this->makeProductStock($product, $locB, ['quantity' => 3, 'quantity_non_tax' => 3]);

        $draft = $this->service->validateAndStructureDraftV2(
            [
                'rows' => [
                    [
                        'product_id' => $product->id,
                        'good_count' => 7,
                        'bad_count' => 1,
                        'serials' => [],
                    ],
                ],
            ],
            [$locB->id, $locA->id] // Intentionally reverse order to test canonicalization
        );

        $this->assertEquals(2, $draft['schema_version']);
        $this->assertArrayHasKey('location_set_fingerprint', $draft);
        $this->assertArrayHasKey('locations', $draft);
        $this->assertArrayHasKey('rows', $draft);
        $this->assertArrayHasKey('baseline_captured_at', $draft);

        // Locations should be sorted ascending by ID
        $locIds = array_column($draft['locations'], 'location_id');
        $this->assertEquals([$locA->id, $locB->id], $locIds);

        // Each location has metadata
        $firstLoc = $draft['locations'][0];
        $this->assertArrayHasKey('setting_id', $firstLoc);
        $this->assertArrayHasKey('setting_name', $firstLoc);
        $this->assertArrayHasKey('is_pkp', $firstLoc);
        $this->assertArrayHasKey('position', $firstLoc);
        $this->assertEquals(1, $firstLoc['position']);

        // Rows have per-location baselines instead of single baseline
        $firstRow = $draft['rows'][0];
        $this->assertArrayHasKey('location_baselines', $firstRow);
        $this->assertCount(2, $firstRow['location_baselines']);
        $this->assertArrayNotHasKey('baseline', $firstRow); // v1 key absent
        $this->assertArrayNotHasKey('tax_allocation', $firstRow); // v1 key absent

        // Per-location baseline has structure
        $lb = $firstRow['location_baselines'][0];
        $this->assertArrayHasKey('location_id', $lb);
        $this->assertArrayHasKey('setting_id', $lb);
        $this->assertArrayHasKey('is_pkp', $lb);
        $this->assertArrayHasKey('existing_good_total', $lb);
        $this->assertArrayHasKey('existing_bad_total', $lb);
        $this->assertArrayHasKey('captured_at', $lb);
    }

    /** @test */
    public function v2_draft_fingerprint_matches_canonicalized_ids(): void
    {
        $this->actingAsUser();
        $setting = $this->makeSetting();
        $locA = $this->makeLocation($setting);
        $locB = $this->makeLocation($setting);
        $product = $this->makeProduct();
        $this->makeProductStock($product, $locA);
        $this->makeProductStock($product, $locB);

        $draft = $this->service->validateAndStructureDraftV2(
            ['rows' => [['product_id' => $product->id, 'good_count' => 5, 'bad_count' => 0, 'serials' => []]]],
            [$locB->id, $locA->id]
        );

        $expectedFp = CountDraftService::locationSetFingerprint([$locA->id, $locB->id]);
        $this->assertEquals($expectedFp, $draft['location_set_fingerprint']);
    }

    // ------------------------------------------------------------------
    //  V2 validation tests
    // ------------------------------------------------------------------

    /** @test */
    public function v2_rejects_empty_location_array(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Minimal satu lokasi');

        $this->service->validateAndStructureDraftV2(
            ['rows' => [['product_id' => 1, 'good_count' => 5, 'bad_count' => 0, 'serials' => []]]],
            []
        );
    }

    /** @test */
    public function v2_rejects_inactive_location(): void
    {
        $this->actingAsUser();
        $setting = $this->makeSetting();
        $loc = $this->makeLocation($setting, ['is_active' => false]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tidak aktif');

        $this->service->validateAndStructureDraftV2(
            ['rows' => [['product_id' => 1, 'good_count' => 5, 'bad_count' => 0, 'serials' => []]]],
            [$loc->id]
        );
    }

    /** @test */
    public function v2_rejects_consignment_location(): void
    {
        $this->actingAsUser();
        $setting = $this->makeSetting();
        $loc = $this->makeLocation($setting, ['is_consignment' => true]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('konsinyasi');

        $this->service->validateAndStructureDraftV2(
            ['rows' => [['product_id' => 1, 'good_count' => 5, 'bad_count' => 0, 'serials' => []]]],
            [$loc->id]
        );
    }

    /** @test */
    public function v2_rejects_empty_product_list(): void
    {
        $this->actingAsUser();
        $setting = $this->makeSetting();
        $loc = $this->makeLocation($setting);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Daftar produk tidak boleh kosong');

        $this->service->validateAndStructureDraftV2(
            ['rows' => []],
            [$loc->id]
        );
    }

    /** @test */
    public function v2_rejects_duplicate_product(): void
    {
        $this->actingAsUser();
        $setting = $this->makeSetting();
        $loc = $this->makeLocation($setting);
        $product = $this->makeProduct();
        $this->makeProductStock($product, $loc);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Produk ganda');

        $this->service->validateAndStructureDraftV2(
            ['rows' => [
                ['product_id' => $product->id, 'good_count' => 5, 'bad_count' => 0, 'serials' => []],
                ['product_id' => $product->id, 'good_count' => 3, 'bad_count' => 0, 'serials' => []],
            ]],
            [$loc->id]
        );
    }

    // ------------------------------------------------------------------
    //  V1 backward compatibility
    // ------------------------------------------------------------------

    /** @test */
    public function v1_draft_structure_is_unchanged(): void
    {
        $this->actingAsUser();
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $product = $this->makeProduct();
        $this->makeProductStock($product, $location);

        session(['setting_id' => $setting->id]);

        $draft = $this->service->validateAndStructureDraft(
            [
                'rows' => [
                    [
                        'product_id' => $product->id,
                        'good_count' => 5,
                        'bad_count' => 1,
                        'serials' => [],
                    ],
                ],
            ],
            $location->id
        );

        $this->assertEquals(1, $draft['schema_version']);
        $this->assertArrayHasKey('location_id', $draft);
        $this->assertArrayHasKey('location_setting_id', $draft);
        $this->assertArrayHasKey('is_pkp', $draft);
        $this->assertArrayNotHasKey('locations', $draft);
        $this->assertArrayNotHasKey('location_set_fingerprint', $draft);

        // Rows have single baseline, not location_baselines
        $row = $draft['rows'][0];
        $this->assertArrayHasKey('baseline', $row);
        $this->assertArrayHasKey('tax_allocation', $row);
        $this->assertArrayNotHasKey('location_baselines', $row);
    }

    // ------------------------------------------------------------------
    //  Cross-setting pool
    // ------------------------------------------------------------------

    /** @test */
    public function v2_captures_per_location_pkp_status(): void
    {
        $this->actingAsUser();
        $pkpSetting = $this->makeSetting(['is_pkp' => true]);
        $nonPkpSetting = $this->makeSetting(['is_pkp' => false]);
        $locPkp = $this->makeLocation($pkpSetting, ['name' => 'PKP Gudang']);
        $locNonPkp = $this->makeLocation($nonPkpSetting, ['name' => 'Non-PKP Gudang']);
        $product = $this->makeProduct();
        $this->makeProductStock($product, $locPkp, ['quantity' => 5, 'quantity_tax' => 5, 'quantity_non_tax' => 0]);
        $this->makeProductStock($product, $locNonPkp, ['quantity' => 3, 'quantity_non_tax' => 3]);

        $draft = $this->service->validateAndStructureDraftV2(
            ['rows' => [['product_id' => $product->id, 'good_count' => 8, 'bad_count' => 0, 'serials' => []]]],
            [$locPkp->id, $locNonPkp->id]
        );

        // Locations metadata captures individual PKP status
        $locMeta = collect($draft['locations']);
        $pkpEntry = $locMeta->firstWhere('location_id', $locPkp->id);
        $nonPkpEntry = $locMeta->firstWhere('location_id', $locNonPkp->id);

        $this->assertTrue($pkpEntry['is_pkp']);
        $this->assertFalse($nonPkpEntry['is_pkp']);

        // Per-location baselines also capture PKP status
        $rowBaselines = collect($draft['rows'][0]['location_baselines']);
        $pkpBaseline = $rowBaselines->firstWhere('location_id', $locPkp->id);
        $nonPkpBaseline = $rowBaselines->firstWhere('location_id', $locNonPkp->id);

        $this->assertTrue($pkpBaseline['is_pkp']);
        $this->assertFalse($nonPkpBaseline['is_pkp']);
    }

    /** @test */
    public function v2_deduplicates_location_ids(): void
    {
        $this->actingAsUser();
        $setting = $this->makeSetting();
        $loc = $this->makeLocation($setting);
        $product = $this->makeProduct();
        $this->makeProductStock($product, $loc);

        // Pass duplicate IDs
        $draft = $this->service->validateAndStructureDraftV2(
            ['rows' => [['product_id' => $product->id, 'good_count' => 5, 'bad_count' => 0, 'serials' => []]]],
            [$loc->id, $loc->id, $loc->id]
        );

        // Should be deduplicated to one location
        $this->assertCount(1, $draft['locations']);
        $this->assertCount(1, $draft['rows'][0]['location_baselines']);
    }
}
