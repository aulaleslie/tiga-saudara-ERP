<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Entities\AdjustmentLocation;
use Modules\Adjustment\Services\SelectedLocationPoolResolver;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

/**
 * Verify the centralized SelectedLocationPoolResolver handles:
 * - multi-location v2 documents via relation rows
 * - historical single-location v1 documents via location_id column
 * - missing, inactive, consignment, duplicate location edge cases
 */
class SelectedLocationPoolResolverTest extends TestCase
{
    use RefreshDatabase;

    private SelectedLocationPoolResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(SelectedLocationPoolResolver::class);
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

    private function makeV2Adjustment(array $locationIds): Adjustment
    {
        $adjustment = Adjustment::create([
            'date' => now()->toDateString(),
            'reference' => 'ADJ-V2-' . uniqid(),
            'note' => null,
            'location_id' => $locationIds[0] ?? null,
            'type' => 'normal',
            'status' => 'draft',
            'count_draft' => [
                'schema_version' => 2,
                'locations' => [],
                'rows' => [],
            ],
        ]);

        foreach ($locationIds as $i => $locId) {
            AdjustmentLocation::create([
                'adjustment_id' => $adjustment->id,
                'location_id' => $locId,
                'position' => $i + 1,
            ]);
        }

        return $adjustment;
    }

    private function makeV1Adjustment(Location $location): Adjustment
    {
        return Adjustment::create([
            'date' => now()->toDateString(),
            'reference' => 'ADJ-V1-' . uniqid(),
            'note' => null,
            'location_id' => $location->id,
            'type' => 'normal',
            'status' => 'draft',
            'count_draft' => [
                'schema_version' => 1,
                'location_id' => $location->id,
                'rows' => [],
            ],
        ]);
    }

    private function makeLegacyAdjustment(Location $location): Adjustment
    {
        return Adjustment::create([
            'date' => now()->toDateString(),
            'reference' => 'ADJ-LEGACY-' . uniqid(),
            'note' => null,
            'location_id' => $location->id,
            'type' => 'normal',
            'status' => 'pending',
        ]);
    }

    // ------------------------------------------------------------------
    //  Multi-location (v2) tests
    // ------------------------------------------------------------------

    /** @test */
    public function resolves_multiple_locations_from_v2_relation(): void
    {
        $setting = $this->makeSetting();
        $locA = $this->makeLocation($setting, ['name' => 'Gudang A']);
        $locB = $this->makeLocation($setting, ['name' => 'Gudang B']);

        $adjustment = $this->makeV2Adjustment([$locA->id, $locB->id]);

        $pool = $this->resolver->resolve($adjustment);

        $this->assertCount(2, $pool);
        $this->assertEquals($locA->id, $pool->first()->id);
        $this->assertEquals($locB->id, $pool->last()->id);
    }

    /** @test */
    public function resolve_ids_returns_sorted_integer_ids_for_v2(): void
    {
        $setting = $this->makeSetting();
        $locA = $this->makeLocation($setting);
        $locB = $this->makeLocation($setting);

        $adjustment = $this->makeV2Adjustment([$locB->id, $locA->id]);

        $ids = $this->resolver->resolveIds($adjustment);

        // resolveIds returns in relation order (position), not sorted by ID
        $this->assertCount(2, $ids);
        $this->assertContainsOnly('int', $ids);
        $this->assertEquals([$locB->id, $locA->id], $ids);
    }

    /** @test */
    public function v2_respects_position_ordering(): void
    {
        $setting = $this->makeSetting();
        $locA = $this->makeLocation($setting, ['name' => 'A']);
        $locB = $this->makeLocation($setting, ['name' => 'B']);
        $locC = $this->makeLocation($setting, ['name' => 'C']);

        // Insert in reverse position order to test ordering
        $adjustment = Adjustment::create([
            'date' => now()->toDateString(),
            'reference' => 'ADJ-V2-' . uniqid(),
            'location_id' => $locA->id,
            'type' => 'normal',
            'status' => 'draft',
            'count_draft' => ['schema_version' => 2, 'locations' => [], 'rows' => []],
        ]);

        AdjustmentLocation::create(['adjustment_id' => $adjustment->id, 'location_id' => $locC->id, 'position' => 3]);
        AdjustmentLocation::create(['adjustment_id' => $adjustment->id, 'location_id' => $locA->id, 'position' => 1]);
        AdjustmentLocation::create(['adjustment_id' => $adjustment->id, 'location_id' => $locB->id, 'position' => 2]);

        $pool = $this->resolver->resolve($adjustment);

        $this->assertEquals([$locA->id, $locB->id, $locC->id], $pool->pluck('id')->all());
    }

    /** @test */
    public function v2_with_no_relation_rows_throws(): void
    {
        $adjustment = Adjustment::create([
            'date' => now()->toDateString(),
            'reference' => 'ADJ-V2-EMPTY-' . uniqid(),
            'type' => 'normal',
            'status' => 'draft',
            'count_draft' => ['schema_version' => 2, 'locations' => [], 'rows' => []],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tidak memiliki lokasi terpilih');

        $this->resolver->resolve($adjustment);
    }

    // ------------------------------------------------------------------
    //  Historical one-location (v1) tests
    // ------------------------------------------------------------------

    /** @test */
    public function resolves_single_location_from_v1_column(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $adjustment = $this->makeV1Adjustment($location);

        $pool = $this->resolver->resolve($adjustment);

        $this->assertCount(1, $pool);
        $this->assertEquals($location->id, $pool->first()->id);
    }

    /** @test */
    public function resolves_single_location_from_legacy_adjustment(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $adjustment = $this->makeLegacyAdjustment($location);

        $pool = $this->resolver->resolve($adjustment);

        $this->assertCount(1, $pool);
        $this->assertEquals($location->id, $pool->first()->id);
    }

    // ------------------------------------------------------------------
    //  Missing location tests
    // ------------------------------------------------------------------

    /** @test */
    public function v2_with_missing_location_throws(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $adjustment = $this->makeV2Adjustment([$location->id]);

        // When a v2 adjustment has no selected locations in relation
        AdjustmentLocation::where('adjustment_id', $adjustment->id)->delete();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tidak memiliki lokasi terpilih');

        $this->resolver->resolve($adjustment->fresh());
    }

    /** @test */
    public function v1_with_missing_location_throws(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $adjustment = $this->makeV1Adjustment($location);

        $location->delete();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tidak ditemukan');

        $this->resolver->resolve($adjustment->fresh());
    }

    /** @test */
    public function legacy_with_no_location_id_throws(): void
    {
        $adjustment = Adjustment::create([
            'date' => now()->toDateString(),
            'reference' => 'ADJ-NOLOC-' . uniqid(),
            'type' => 'normal',
            'status' => 'pending',
            'location_id' => null,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tidak memiliki lokasi tujuan');

        $this->resolver->resolve($adjustment);
    }

    // ------------------------------------------------------------------
    //  Inactive location tests
    // ------------------------------------------------------------------

    /** @test */
    public function v2_with_inactive_location_throws(): void
    {
        $setting = $this->makeSetting();
        $locA = $this->makeLocation($setting, ['name' => 'Active']);
        $locB = $this->makeLocation($setting, ['name' => 'Inactive', 'is_active' => false]);

        $adjustment = $this->makeV2Adjustment([$locA->id, $locB->id]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tidak aktif');

        $this->resolver->resolve($adjustment);
    }

    /** @test */
    public function v1_with_inactive_location_throws(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting, ['is_active' => false]);
        $adjustment = $this->makeV1Adjustment($location);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tidak aktif');

        $this->resolver->resolve($adjustment);
    }

    // ------------------------------------------------------------------
    //  Consignment location tests
    // ------------------------------------------------------------------

    /** @test */
    public function v2_with_consignment_location_throws(): void
    {
        $setting = $this->makeSetting();
        $locA = $this->makeLocation($setting, ['name' => 'Standard']);
        $locB = $this->makeLocation($setting, ['name' => 'Consign', 'is_consignment' => true]);

        $adjustment = $this->makeV2Adjustment([$locA->id, $locB->id]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('konsinyasi');

        $this->resolver->resolve($adjustment);
    }

    /** @test */
    public function v1_with_consignment_location_throws(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting, ['is_consignment' => true]);
        $adjustment = $this->makeV1Adjustment($location);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('konsinyasi');

        $this->resolver->resolve($adjustment);
    }

    // ------------------------------------------------------------------
    //  Cross-setting (multi-setting) v2 pool
    // ------------------------------------------------------------------

    /** @test */
    public function v2_resolves_locations_from_different_settings(): void
    {
        $settingA = $this->makeSetting(['is_pkp' => true]);
        $settingB = $this->makeSetting(['is_pkp' => false]);
        $locA = $this->makeLocation($settingA, ['name' => 'Gudang PKP']);
        $locB = $this->makeLocation($settingB, ['name' => 'Gudang Non-PKP']);

        $adjustment = $this->makeV2Adjustment([$locA->id, $locB->id]);

        $pool = $this->resolver->resolve($adjustment);

        $this->assertCount(2, $pool);
        // Each location retains its own setting
        $this->assertTrue((bool) $pool->first()->setting->is_pkp);
        $this->assertFalse((bool) $pool->last()->setting->is_pkp);
    }
}
