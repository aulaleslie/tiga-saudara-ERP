<?php

namespace Modules\Purchase\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifies the uom_normalization_batches/lines migration history is safe
 * for both a fresh install and an upgrade from the exact schema already
 * executed in production (2026_08_13_000001_create_uom_normalization_tables),
 * without dropping or rewriting that executed migration.
 */
class UomNormalizationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_database_migrates_and_has_full_current_schema()
    {
        // RefreshDatabase already ran the entire current migration chain
        // (original create + additive upgrades) against an empty database.
        $this->assertTrue(Schema::hasTable('uom_normalization_batches'));
        $this->assertTrue(Schema::hasTable('uom_normalization_lines'));

        // Legacy columns from the originally executed migration are present
        // and untouched in shape (still exist, just relaxed to nullable).
        foreach (['product_unit_conversion_id', 'source_unit_id', 'base_unit_id'] as $legacyColumn) {
            $this->assertTrue(Schema::hasColumn('uom_normalization_batches', $legacyColumn));
        }

        // New base-UOM-correction audit columns exist.
        foreach ([
            'old_primary_unit_id',
            'new_primary_unit_id',
            'old_base_unit_id',
            'new_base_unit_id',
            'rounding_amount',
            'is_acknowledged',
            'is_sales_price_warning_acknowledged',
            'conversion_barcode_changes',
            'location_snapshots',
        ] as $newColumn) {
            $this->assertTrue(Schema::hasColumn('uom_normalization_batches', $newColumn));
        }

        // Line-level unit price audit columns from the additive line migration.
        $this->assertTrue(Schema::hasColumn('uom_normalization_lines', 'normalized_unit_price'));
        $this->assertTrue(Schema::hasColumn('uom_normalization_lines', 'unit_price_rounding_effect'));
    }

    public function test_upgrade_from_exact_deployed_schema_preserves_historical_rows()
    {
        // Simulate the exact table shape the original, already-executed
        // production migration created (product_unit_conversion_id,
        // source_unit_id, base_unit_id; no new audit columns) by dropping
        // the current tables and recreating them via the ORIGINAL migration
        // class body only, then inserting a "historical" row exactly as
        // production would have it, THEN replaying the additive upgrade
        // migrations on top — proving no historical data is lost or requires
        // fabricated values.
        Schema::dropIfExists('uom_normalization_lines');
        Schema::dropIfExists('uom_normalization_batches');

        $originalMigration = require base_path(
            'Modules/Purchase/Database/Migrations/2026_08_13_000001_create_uom_normalization_tables.php'
        );
        $originalMigration->up();

        // Confirm this reproduces the exact deployed (pre-upgrade) shape.
        $this->assertFalse(Schema::hasColumn('uom_normalization_batches', 'old_primary_unit_id'));
        $this->assertTrue(Schema::hasColumn('uom_normalization_batches', 'product_unit_conversion_id'));
        $this->assertTrue(Schema::hasColumn('uom_normalization_batches', 'source_unit_id'));
        $this->assertTrue(Schema::hasColumn('uom_normalization_batches', 'base_unit_id'));

        [$settingId, $productId, $actorId, $conversionId, $unitId] = $this->seedMinimalFixtures();

        // A historical batch row exactly as production's original schema
        // would have stored it (no new audit columns exist yet).
        $historicalBatchId = DB::table('uom_normalization_batches')->insertGetId([
            'setting_id' => $settingId,
            'product_id' => $productId,
            'product_unit_conversion_id' => $conversionId,
            'actor_user_id' => $actorId,
            'status' => 'EXECUTED',
            'reason' => 'Historical legacy normalization',
            'source_unit_id' => $unitId,
            'base_unit_id' => $unitId,
            'conversion_factor' => 12,
            'executed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Now replay the additive upgrade migrations on top of this
        // production-shaped state.
        $batchAuditMigration = require base_path(
            'Modules/Purchase/Database/Migrations/2026_08_13_100000_add_base_uom_correction_audit_columns_to_uom_normalization_batches.php'
        );
        $batchAuditMigration->up();

        $lineAuditMigration = require base_path(
            'Modules/Purchase/Database/Migrations/2026_08_13_200000_add_unit_price_audit_to_uom_normalization_lines.php'
        );
        $lineAuditMigration->up();

        // The historical row survives untouched, with new columns NULL
        // rather than fabricated.
        $row = DB::table('uom_normalization_batches')->where('id', $historicalBatchId)->first();
        $this->assertNotNull($row);
        $this->assertEquals('Historical legacy normalization', $row->reason);
        $this->assertEquals($conversionId, $row->product_unit_conversion_id);
        $this->assertEquals($unitId, $row->source_unit_id);
        $this->assertEquals($unitId, $row->base_unit_id);
        $this->assertNull($row->old_primary_unit_id);
        $this->assertNull($row->new_primary_unit_id);
        $this->assertNull($row->old_base_unit_id);
        $this->assertNull($row->new_base_unit_id);
        $this->assertNull($row->conversion_barcode_changes);
        $this->assertNull($row->location_snapshots);

        // A NEW-style insert (new columns populated, legacy columns left
        // null) now succeeds without violating any constraint.
        $newBatchId = DB::table('uom_normalization_batches')->insertGetId([
            'setting_id' => $settingId,
            'product_id' => $productId,
            'actor_user_id' => $actorId,
            'status' => 'EXECUTED',
            'reason' => 'New-style correction',
            'old_primary_unit_id' => $unitId,
            'new_primary_unit_id' => $unitId,
            'old_base_unit_id' => $unitId,
            'new_base_unit_id' => $unitId,
            'conversion_factor' => 10,
            'rounding_amount' => 0,
            'is_acknowledged' => true,
            'is_sales_price_warning_acknowledged' => true,
            'conversion_barcode_changes' => json_encode([]),
            'location_snapshots' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $newRow = DB::table('uom_normalization_batches')->where('id', $newBatchId)->first();
        $this->assertNotNull($newRow);
        $this->assertNull($newRow->product_unit_conversion_id);
        $this->assertNull($newRow->source_unit_id);
        $this->assertNull($newRow->base_unit_id);
        $this->assertEquals($unitId, $newRow->old_primary_unit_id);
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int} settingId, productId, actorId, conversionId, unitId
     */
    private function seedMinimalFixtures(): array
    {
        \Modules\Currency\Entities\Currency::firstOrCreate(['id' => 1], [
            'currency_name' => 'Rupiah',
            'code' => 'IDR',
            'symbol' => 'Rp',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'exchange_rate' => 1,
        ]);

        $setting = \Modules\Setting\Entities\Setting::firstOrCreate(['id' => 1], [
            'company_name' => 'Test Company',
            'company_email' => 'test@company.com',
            'company_phone' => '1234567890',
            'company_address' => 'Test Address',
            'default_currency_id' => 1,
            'default_currency_position' => 'prefix',
            'notification_email' => 'notification@test.com',
            'footer_text' => 'Test Footer',
        ]);

        $unit = \Modules\Setting\Entities\Unit::create(['name' => 'MigUnit' . uniqid(), 'short_name' => 'MU']);

        $product = \Modules\Product\Entities\Product::create([
            'product_name' => 'Migration Test Product',
            'product_code' => 'MIG-' . uniqid(),
            'product_cost' => 100,
            'product_price' => 200,
            'product_quantity' => 0,
            'setting_id' => $setting->id,
            'stock_managed' => true,
            'base_unit_id' => $unit->id,
            'unit_id' => $unit->id,
        ]);

        $conversion = \Modules\Product\Entities\ProductUnitConversion::create([
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'base_unit_id' => $unit->id,
            'conversion_factor' => 1,
        ]);

        $actor = \App\Models\User::factory()->create(['is_active' => 1]);

        return [$setting->id, $product->id, $actor->id, $conversion->id, $unit->id];
    }
}
