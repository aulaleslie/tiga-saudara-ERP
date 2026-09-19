<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Entities\AdjustmentLocation;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

/**
 * Verify the adjustment_locations pivot table migration: schema, constraints,
 * model relationships, and rollback safety.
 */
class AdjustmentLocationMigrationTest extends TestCase
{
    use RefreshDatabase;

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

    private function makeAdjustment(Location $location): Adjustment
    {
        return Adjustment::create([
            'date' => now()->toDateString(),
            'reference' => 'ADJ-TEST-' . uniqid(),
            'note' => null,
            'location_id' => $location->id,
            'type' => 'normal',
            'status' => 'draft',
        ]);
    }

    // ------------------------------------------------------------------
    //  Schema assertions
    // ------------------------------------------------------------------

    /** @test */
    public function adjustment_locations_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('adjustment_locations'));
    }

    /** @test */
    public function adjustment_locations_has_required_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('adjustment_locations', [
            'id',
            'adjustment_id',
            'location_id',
            'position',
            'created_at',
            'updated_at',
        ]));
    }

    // ------------------------------------------------------------------
    //  CRUD and relationship assertions
    // ------------------------------------------------------------------

    /** @test */
    public function can_create_adjustment_location_row(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $adjustment = $this->makeAdjustment($location);

        $row = AdjustmentLocation::create([
            'adjustment_id' => $adjustment->id,
            'location_id' => $location->id,
            'position' => 1,
        ]);

        $this->assertDatabaseHas('adjustment_locations', [
            'id' => $row->id,
            'adjustment_id' => $adjustment->id,
            'location_id' => $location->id,
            'position' => 1,
        ]);
    }

    /** @test */
    public function adjustment_has_many_selected_locations(): void
    {
        $setting = $this->makeSetting();
        $locA = $this->makeLocation($setting, ['name' => 'A']);
        $locB = $this->makeLocation($setting, ['name' => 'B']);
        $adjustment = $this->makeAdjustment($locA);

        AdjustmentLocation::create([
            'adjustment_id' => $adjustment->id,
            'location_id' => $locA->id,
            'position' => 2,
        ]);
        AdjustmentLocation::create([
            'adjustment_id' => $adjustment->id,
            'location_id' => $locB->id,
            'position' => 1,
        ]);

        $selected = $adjustment->selectedLocations()->get();

        $this->assertCount(2, $selected);
        // Ordered by position ASC, then location_id ASC
        $this->assertEquals($locB->id, $selected->first()->location_id);
        $this->assertEquals($locA->id, $selected->last()->location_id);
    }

    /** @test */
    public function adjustment_location_belongs_to_adjustment(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $adjustment = $this->makeAdjustment($location);

        $row = AdjustmentLocation::create([
            'adjustment_id' => $adjustment->id,
            'location_id' => $location->id,
            'position' => 1,
        ]);

        $this->assertTrue($row->adjustment->is($adjustment));
    }

    /** @test */
    public function adjustment_location_belongs_to_location(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $adjustment = $this->makeAdjustment($location);

        $row = AdjustmentLocation::create([
            'adjustment_id' => $adjustment->id,
            'location_id' => $location->id,
            'position' => 1,
        ]);

        $this->assertTrue($row->location->is($location));
    }

    // ------------------------------------------------------------------
    //  Constraint assertions
    // ------------------------------------------------------------------

    /** @test */
    public function duplicate_adjustment_location_pair_is_rejected(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $adjustment = $this->makeAdjustment($location);

        AdjustmentLocation::create([
            'adjustment_id' => $adjustment->id,
            'location_id' => $location->id,
            'position' => 1,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        AdjustmentLocation::create([
            'adjustment_id' => $adjustment->id,
            'location_id' => $location->id,
            'position' => 2,
        ]);
    }

    /** @test */
    public function same_location_can_belong_to_different_adjustments(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $adjA = $this->makeAdjustment($location);
        $adjB = $this->makeAdjustment($location);

        AdjustmentLocation::create([
            'adjustment_id' => $adjA->id,
            'location_id' => $location->id,
            'position' => 1,
        ]);

        AdjustmentLocation::create([
            'adjustment_id' => $adjB->id,
            'location_id' => $location->id,
            'position' => 1,
        ]);

        $this->assertDatabaseCount('adjustment_locations', 2);
    }

    /** @test */
    public function cascade_deletes_rows_when_adjustment_is_deleted(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $adjustment = $this->makeAdjustment($location);

        AdjustmentLocation::create([
            'adjustment_id' => $adjustment->id,
            'location_id' => $location->id,
            'position' => 1,
        ]);

        $this->assertDatabaseCount('adjustment_locations', 1);

        $adjustment->delete();

        $this->assertDatabaseCount('adjustment_locations', 0);
    }

    // ------------------------------------------------------------------
    //  Model helper assertions
    // ------------------------------------------------------------------

    /** @test */
    public function is_schema_version_2_returns_true_for_v2_documents(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $adjustment = $this->makeAdjustment($location);
        $adjustment->update([
            'count_draft' => ['schema_version' => 2, 'locations' => [], 'rows' => []],
        ]);

        $this->assertTrue($adjustment->fresh()->isSchemaVersion2());
    }

    /** @test */
    public function is_schema_version_2_returns_false_for_v1_documents(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $adjustment = $this->makeAdjustment($location);
        $adjustment->update([
            'count_draft' => ['schema_version' => 1, 'location_id' => $location->id, 'rows' => []],
        ]);

        $this->assertFalse($adjustment->fresh()->isSchemaVersion2());
    }

    /** @test */
    public function is_schema_version_2_returns_false_for_legacy_documents(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $adjustment = $this->makeAdjustment($location);
        // Legacy documents have no count_draft at all
        $adjustment->update(['count_draft' => null]);

        $this->assertFalse($adjustment->fresh()->isSchemaVersion2());
    }

    /** @test */
    public function position_is_nullable(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $adjustment = $this->makeAdjustment($location);

        $row = AdjustmentLocation::create([
            'adjustment_id' => $adjustment->id,
            'location_id' => $location->id,
            'position' => null,
        ]);

        $this->assertNull($row->fresh()->position);
    }

    /** @test */
    public function location_deletion_is_restricted_when_referenced_by_adjustment_location(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $adjustment = $this->makeAdjustment($location);

        AdjustmentLocation::create([
            'adjustment_id' => $adjustment->id,
            'location_id' => $location->id,
            'position' => 0,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $location->delete();
    }

    /** @test */
    public function rollback_is_prevented_when_v2_adjustment_locations_exist(): void
    {
        $setting = $this->makeSetting();
        $location = $this->makeLocation($setting);
        $adjustment = $this->makeAdjustment($location);

        AdjustmentLocation::create([
            'adjustment_id' => $adjustment->id,
            'location_id' => $location->id,
            'position' => 0,
        ]);

        $migration = require base_path('Modules/Adjustment/Database/Migrations/2026_09_19_200000_create_adjustment_locations_table.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot rollback adjustment_locations table while schema-version-2 stock opname records exist.');

        $migration->down();
    }
}
