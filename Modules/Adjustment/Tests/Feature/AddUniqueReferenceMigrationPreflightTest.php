<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Currency\Entities\Currency;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Tests\TestCase;

/**
 * Regression coverage for the 2026_09_11_100100_add_unique_reference_to_
 * adjustments_table migration's preflight check: it must only silently
 * auto-repair the two known literal placeholders ("ADJ"/"BRK"), and must
 * fail loudly with an actionable message -- not a raw database constraint
 * error -- for any OTHER duplicate reference (e.g. two rows that already
 * share a real generated or manually-edited value), since there is no safe
 * automatic policy for which of those rows is "correct".
 */
class AddUniqueReferenceMigrationPreflightTest extends TestCase
{
    use RefreshDatabase;

    private function makeLocation(): Location
    {
        $currency = Currency::create([
            'currency_name' => 'Rupiah', 'code' => 'IDR', 'symbol' => 'RP',
            'thousand_separator' => '.', 'decimal_separator' => ',', 'exchange_rate' => 1,
        ]);

        $setting = Setting::create([
            'company_name' => 'CV Tiga Computer ' . uniqid(),
            'company_email' => 'ops@tiga.test',
            'company_phone' => '0800000000',
            'default_currency_id' => $currency->id,
            'default_currency_position' => 'prefix',
            'notification_email' => 'ops@tiga.test',
            'footer_text' => 'Footer',
            'company_address' => 'Bandung',
            'is_pkp' => true,
        ]);

        return Location::create(['setting_id' => $setting->id, 'name' => 'Gudang']);
    }

    private function loadMigrationInstance(): object
    {
        return require base_path('database/migrations/2026_09_11_100100_add_unique_reference_to_adjustments_table.php');
    }

    private function dropExistingUniqueConstraint(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS adjustments_reference_unique');
        } else {
            DB::statement('ALTER TABLE adjustments DROP INDEX adjustments_reference_unique');
        }
    }

    /** @test */
    public function migration_throws_an_actionable_exception_for_a_duplicate_real_reference(): void
    {
        $location = $this->makeLocation();

        // The schema already has the constraint applied by RefreshDatabase's
        // migration run; drop it so we can seed the exact pre-migration
        // duplicate state this test needs, then re-run the migration's up()
        // against that state.
        $this->dropExistingUniqueConstraint();

        DB::table('adjustments')->insert([
            ['date' => now(), 'type' => 'breakage', 'status' => 'pending', 'location_id' => $location->id, 'reference' => 'BRK-2026-01-00007', 'created_at' => now(), 'updated_at' => now()],
            ['date' => now(), 'type' => 'breakage', 'status' => 'pending', 'location_id' => $location->id, 'reference' => 'BRK-2026-01-00007', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $migration = $this->loadMigrationInstance();

        try {
            $migration->up();
            $this->fail('Expected the migration to throw for a duplicate non-placeholder reference.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('BRK-2026-01-00007', $e->getMessage());
            $this->assertStringContainsString('unique constraint', strtolower($e->getMessage()));
        }

        // Confirm the constraint was NOT silently added despite the failure.
        $this->assertGreaterThan(
            1,
            DB::table('adjustments')->where('reference', 'BRK-2026-01-00007')->count()
        );
    }

    /** @test */
    public function migration_auto_repairs_literal_placeholder_duplicates_then_succeeds(): void
    {
        $location = $this->makeLocation();
        $this->dropExistingUniqueConstraint();

        DB::table('adjustments')->insert([
            ['date' => now(), 'type' => 'breakage', 'status' => 'pending', 'location_id' => $location->id, 'reference' => 'BRK', 'created_at' => now(), 'updated_at' => now()],
            ['date' => now(), 'type' => 'breakage', 'status' => 'pending', 'location_id' => $location->id, 'reference' => 'BRK', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $migration = $this->loadMigrationInstance();
        $migration->up();

        $references = DB::table('adjustments')->pluck('reference');
        $this->assertSame($references->unique()->count(), $references->count());

        // The constraint must actually be in effect now.
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('adjustments')->insert([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending',
            'location_id' => $location->id, 'reference' => $references->first(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @test */
    public function migration_repairs_a_single_legacy_placeholder_row_not_just_when_duplicated(): void
    {
        $location = $this->makeLocation();
        $this->dropExistingUniqueConstraint();

        // A single legacy row does not violate uniqueness by itself, so
        // the constraint could apply successfully while leaving this row
        // literally "BRK" forever if the migration only repairs when a
        // duplicate is actually blocking the index. It must still be
        // repaired.
        DB::table('adjustments')->insert([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending',
            'location_id' => $location->id, 'reference' => 'BRK',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = $this->loadMigrationInstance();
        $migration->up();

        $reference = DB::table('adjustments')->where('location_id', $location->id)->value('reference');
        $this->assertNotSame('BRK', $reference);
        $this->assertMatchesRegularExpression('/^BRK-\d{4}-\d{2}-\d{5}$/', $reference);
    }
}
