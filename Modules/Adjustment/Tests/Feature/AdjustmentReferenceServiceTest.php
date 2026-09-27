<?php

declare(strict_types=1);

namespace Modules\Adjustment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Services\AdjustmentReferenceService;
use Modules\Setting\Entities\Location;
use Modules\Setting\Entities\Setting;
use Modules\Currency\Entities\Currency;
use Tests\TestCase;

/**
 * Regression coverage for the ADJ/BRK reference-generation fix: normal and
 * breakage adjustments must be allocated distinct, monotonically increasing
 * reference numbers within their own prefix namespace, never inspecting or
 * being perturbed by the other prefix's rows in the same month.
 */
class AdjustmentReferenceServiceTest extends TestCase
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

    /** @test */
    public function normal_adjustment_creation_generates_an_adj_reference_when_placeholder_submitted(): void
    {
        $location = $this->makeLocation();

        $adjustment = Adjustment::create([
            'date' => now(), 'type' => 'normal', 'status' => 'pending',
            'location_id' => $location->id, 'reference' => 'ADJ',
        ]);

        $this->assertMatchesRegularExpression('/^ADJ-\d{4}-\d{2}-\d{5}$/', $adjustment->reference);
    }

    /** @test */
    public function breakage_adjustment_creation_generates_a_brk_reference_when_placeholder_submitted(): void
    {
        $location = $this->makeLocation();

        $adjustment = Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending',
            'location_id' => $location->id, 'reference' => 'BRK',
        ]);

        $this->assertMatchesRegularExpression('/^BRK-\d{4}-\d{2}-\d{5}$/', $adjustment->reference);
    }

    /** @test */
    public function breakage_reference_is_never_left_as_the_literal_placeholder(): void
    {
        $location = $this->makeLocation();

        $adjustment = Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending',
            'location_id' => $location->id, 'reference' => 'BRK',
        ]);

        $this->assertNotSame('BRK', $adjustment->reference);
        $this->assertStringStartsWith('BRK-', $adjustment->reference);
    }

    /** @test */
    public function adj_and_brk_sequences_are_independent_namespaces_within_the_same_month(): void
    {
        $location = $this->makeLocation();

        $normal1 = Adjustment::create([
            'date' => now(), 'type' => 'normal', 'status' => 'pending',
            'location_id' => $location->id, 'reference' => 'ADJ',
        ]);
        $breakage1 = Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending',
            'location_id' => $location->id, 'reference' => 'BRK',
        ]);
        $normal2 = Adjustment::create([
            'date' => now(), 'type' => 'normal', 'status' => 'pending',
            'location_id' => $location->id, 'reference' => 'ADJ',
        ]);
        $breakage2 = Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending',
            'location_id' => $location->id, 'reference' => 'BRK',
        ]);

        // Each prefix's sequence advances independently of the other
        // prefix's activity in the same month -- BRK creation in between
        // must not have consumed an ADJ number, and vice versa.
        $this->assertStringContainsString('-00001', $normal1->reference);
        $this->assertStringContainsString('-00002', $normal2->reference);
        $this->assertStringContainsString('-00001', $breakage1->reference);
        $this->assertStringContainsString('-00002', $breakage2->reference);
    }

    /** @test */
    public function explicit_caller_supplied_reference_is_preserved_verbatim(): void
    {
        $location = $this->makeLocation();

        $adjustment = Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending',
            'location_id' => $location->id, 'reference' => 'BRK-2020-01-00099',
        ]);

        $this->assertSame('BRK-2020-01-00099', $adjustment->reference);
    }

    /** @test */
    public function legacy_literal_brk_record_is_detected_as_needing_generation(): void
    {
        $this->assertTrue(AdjustmentReferenceService::needsGeneration('BRK', AdjustmentReferenceService::PREFIX_BREAKAGE));
        $this->assertTrue(AdjustmentReferenceService::needsGeneration('brk', AdjustmentReferenceService::PREFIX_BREAKAGE));
        $this->assertTrue(AdjustmentReferenceService::needsGeneration('', AdjustmentReferenceService::PREFIX_BREAKAGE));
        $this->assertTrue(AdjustmentReferenceService::needsGeneration(null, AdjustmentReferenceService::PREFIX_BREAKAGE));
        $this->assertFalse(AdjustmentReferenceService::needsGeneration('BRK-2026-09-00001', AdjustmentReferenceService::PREFIX_BREAKAGE));
    }

    /** @test */
    public function backfill_command_repairs_a_legacy_literal_brk_reference(): void
    {
        $location = $this->makeLocation();

        // Simulate the historical bug by writing the literal placeholder
        // directly, bypassing the model's creating() hook via forceFill +
        // saveQuietly (mirrors how the bug produced these rows in
        // production before this fix).
        $adjustment = new Adjustment();
        $adjustment->forceFill([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending',
            'location_id' => $location->id, 'reference' => 'BRK-2020-01-00099',
        ]);
        $adjustment->saveQuietly();
        Adjustment::withoutEvents(function () use ($adjustment) {
            $adjustment->update(['reference' => 'BRK']);
        });

        $this->assertSame('BRK', $adjustment->fresh()->reference);

        $this->artisan('adjustments:backfill-references', ['--apply' => true])
            ->assertSuccessful();

        $repaired = $adjustment->fresh();
        $this->assertNotSame('BRK', $repaired->reference);
        $this->assertMatchesRegularExpression('/^BRK-\d{4}-\d{2}-\d{5}$/', $repaired->reference);
    }

    /** @test */
    public function backfill_command_dry_run_does_not_persist_changes(): void
    {
        $location = $this->makeLocation();

        $adjustment = new Adjustment();
        $adjustment->forceFill([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending',
            'location_id' => $location->id, 'reference' => 'BRK-2020-01-00099',
        ]);
        $adjustment->saveQuietly();
        Adjustment::withoutEvents(function () use ($adjustment) {
            $adjustment->update(['reference' => 'BRK']);
        });

        $this->artisan('adjustments:backfill-references')
            ->assertSuccessful();

        $this->assertSame('BRK', $adjustment->fresh()->reference);

        // Dry-run must be genuinely read-only: it must not create, lock,
        // or update any adjustment_reference_sequences row -- previewing
        // a candidate reference is not the same as reserving it.
        $this->assertSame(
            0,
            \Modules\Adjustment\Entities\AdjustmentReferenceSequence::where('prefix', 'BRK')->count(),
            'Dry-run must not write to adjustment_reference_sequences.'
        );
    }

    /** @test */
    public function backfill_command_dry_run_does_not_create_a_sequence_counter_row_even_when_namespace_has_no_counter_yet(): void
    {
        $location = $this->makeLocation();

        // No adjustment_reference_sequences row exists yet for ADJ in this
        // namespace -- previewNextReference() must be able to compute a
        // preview purely by reading (never creating) that row.
        $this->assertSame(0, \Modules\Adjustment\Entities\AdjustmentReferenceSequence::where('prefix', 'ADJ')->count());

        $adjustment = new Adjustment();
        $adjustment->forceFill([
            'date' => now(), 'type' => 'normal', 'status' => 'pending',
            'location_id' => $location->id, 'reference' => 'ADJ',
        ]);
        $adjustment->saveQuietly();

        $this->artisan('adjustments:backfill-references')
            ->expectsOutputToContain('=> ADJ-'.now()->format('Y-m').'-00001')
            ->assertSuccessful();

        $this->assertSame(
            0,
            \Modules\Adjustment\Entities\AdjustmentReferenceSequence::where('prefix', 'ADJ')->count(),
            'Dry-run must not create a counter row for a namespace it only previewed.'
        );
    }

    /**
     * Drops the adjustments.reference unique constraint for the remainder
     * of the current test only (restored automatically when
     * RefreshDatabase rolls back the test's wrapping transaction). Needed
     * to seed multiple rows sharing the exact literal placeholder
     * ("BRK"/"ADJ"), reproducing the state production genuinely
     * accumulated under the old buggy generation logic -- a state this
     * fix's constraint correctly makes unreachable going forward.
     */
    private function dropReferenceUniqueConstraintForThisTest(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS adjustments_reference_unique');
        } else {
            DB::statement('ALTER TABLE adjustments DROP INDEX adjustments_reference_unique');
        }
    }

    private function makeLegacyPlaceholderRow(Location $location, string $type, string $placeholder): Adjustment
    {
        $adjustment = new Adjustment();
        $adjustment->forceFill([
            'date' => now(), 'type' => $type, 'status' => 'pending',
            'location_id' => $location->id, 'reference' => $placeholder,
        ]);
        $adjustment->saveQuietly();

        return $adjustment;
    }

    /** @test */
    public function backfill_never_reissues_a_number_already_held_by_a_higher_id_row_in_the_same_namespace(): void
    {
        $location = $this->makeLocation();

        // A newer row (higher id) already holds BRK-<...>-00001 via the
        // normal generation path.
        $numbered = Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending',
            'location_id' => $location->id, 'reference' => 'BRK',
        ]);
        $this->assertStringContainsString('-00001', $numbered->reference);

        // An older-dated, but lower-id-relative-to-scan-order, legacy row
        // still carries the literal placeholder and needs repair. Before
        // the counter-table fix, the allocator derived "next" from
        // whichever row had the greatest id (the already-numbered one),
        // so repairing this row could reissue 00001 -- now a duplicate of
        // $numbered's reference.
        $legacy = $this->makeLegacyPlaceholderRow($location, 'breakage', 'BRK');

        $this->artisan('adjustments:backfill-references', ['--apply' => true])
            ->assertSuccessful();

        $repaired = $legacy->fresh();
        $numberedFresh = $numbered->fresh();

        $this->assertNotSame($numberedFresh->reference, $repaired->reference);
        $this->assertStringContainsString('-00002', $repaired->reference);
    }

    /** @test */
    public function backfill_assigns_distinct_sequential_numbers_across_multiple_legacy_rows_in_one_pass(): void
    {
        $location = $this->makeLocation();
        $this->dropReferenceUniqueConstraintForThisTest();

        $legacyOne = $this->makeLegacyPlaceholderRow($location, 'breakage', 'BRK');
        $legacyTwo = $this->makeLegacyPlaceholderRow($location, 'breakage', 'BRK');
        $legacyThree = $this->makeLegacyPlaceholderRow($location, 'breakage', 'BRK');

        $this->artisan('adjustments:backfill-references', ['--apply' => true])
            ->assertSuccessful();

        $references = collect([$legacyOne, $legacyTwo, $legacyThree])
            ->map(fn (Adjustment $a) => $a->fresh()->reference);

        $this->assertSame($references->unique()->count(), $references->count(), 'Expected all backfilled references to be distinct.');
        $this->assertTrue($references->every(fn ($r) => str_starts_with($r, 'BRK-')));
    }

    /** @test */
    public function backfill_dry_run_previews_distinct_incrementing_numbers_for_multiple_legacy_rows(): void
    {
        $location = $this->makeLocation();
        $this->dropReferenceUniqueConstraintForThisTest();

        $this->makeLegacyPlaceholderRow($location, 'breakage', 'BRK');
        $this->makeLegacyPlaceholderRow($location, 'breakage', 'BRK');

        // Both CAND lines must propose distinct numbers, not the same
        // "next" value twice -- the historical bug this guards is a
        // dry-run reporting the same proposed reference for every
        // placeholder row in a namespace because it re-derived "next"
        // from the database on each call rather than simulating the
        // sequence in memory.
        $this->artisan('adjustments:backfill-references')
            ->expectsOutputToContain('=> BRK-'.now()->format('Y-m').'-00001')
            ->expectsOutputToContain('=> BRK-'.now()->format('Y-m').'-00002')
            ->assertSuccessful();

        // Dry-run must not have persisted either proposed reference.
        $this->assertSame(2, Adjustment::where('reference', 'BRK')->count());
    }

    /** @test */
    public function sequential_allocations_in_the_same_namespace_never_produce_duplicate_references(): void
    {
        $location = $this->makeLocation();

        // NOTE: this exercises correctness of sequential allocate() calls
        // only -- two transactions here run one after another on a single
        // connection, so nothing is actually contending for the counter
        // row's lock. It does NOT prove the FOR UPDATE lock serializes
        // genuinely concurrent allocators from independent connections;
        // that is covered separately by the real multi-process test
        // tests/Feature/Services/Sequence/Concurrency/AdjustmentReferenceConcurrencyWorkerTest.php
        // (@group mysql, run via scripts/run-mysql-sequence-tests.sh),
        // which spawns separate PHP processes with their own MySQL
        // connections racing on the same namespace.
        $referenceA = null;
        $referenceB = null;

        DB::transaction(function () use (&$referenceA) {
            $referenceA = AdjustmentReferenceService::allocate('BRK');
        });

        DB::transaction(function () use (&$referenceB) {
            $referenceB = AdjustmentReferenceService::allocate('BRK');
        });

        $this->assertNotSame($referenceA, $referenceB);
        $this->assertStringContainsString('-00001', $referenceA);
        $this->assertStringContainsString('-00002', $referenceB);
    }

    /** @test */
    public function allocate_reconciles_a_fresh_counter_against_a_preexisting_real_reference(): void
    {
        $location = $this->makeLocation();

        // Simulates deploying this fix onto an installation that already
        // has a real numbered reference in the adjustments table, but
        // whose adjustment_reference_sequences counter table is brand new
        // (last_number defaults to 0 for a namespace it has never seen).
        // Before the reconcile-on-every-call fix, allocate() would trust
        // the fresh row's last_number=0 and issue "...-00001" again here,
        // which the adjustments.reference unique constraint would then
        // reject -- and every subsequent retry would fail identically,
        // since nothing ever advanced the counter past 0.
        Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending',
            'location_id' => $location->id, 'reference' => 'BRK-'.now()->format('Y-m').'-00001',
        ]);

        // No row exists yet in adjustment_reference_sequences for this
        // namespace -- allocate() must create it AND reconcile it past
        // the existing real reference before incrementing.
        $this->assertSame(
            0,
            \Modules\Adjustment\Entities\AdjustmentReferenceSequence::where('prefix', 'BRK')->count()
        );

        $second = Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending',
            'location_id' => $location->id, 'reference' => 'BRK',
        ]);

        $this->assertStringContainsString('-00002', $second->reference);
    }

    /** @test */
    public function database_unique_constraint_rejects_a_duplicate_reference_as_a_final_backstop(): void
    {
        $location = $this->makeLocation();

        Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending',
            'location_id' => $location->id, 'reference' => 'BRK-2026-09-00001',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Bypasses the allocator entirely (forceFill + save, no creating()
        // interception needed since an explicit, already-numbered
        // reference is supplied) to prove the database itself refuses a
        // literal duplicate as a final backstop independent of any
        // application-level locking.
        Adjustment::create([
            'date' => now(), 'type' => 'breakage', 'status' => 'pending',
            'location_id' => $location->id, 'reference' => 'BRK-2026-09-00001',
        ]);
    }
}
