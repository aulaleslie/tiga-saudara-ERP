<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Repair every row still carrying a literal placeholder reference
        // ("ADJ" / "BRK") before adding the constraint -- not just when
        // more than one such row exists. A single legacy placeholder row
        // does not violate uniqueness on its own (so the index would
        // apply successfully either way), but leaving it unrepaired means
        // the intended historical fix silently does not happen for that
        // environment: the row keeps displaying the literal "BRK"/"ADJ"
        // forever, since nothing else ever revisits it. Using exists()
        // rather than count() > 1 ensures this migration always finishes
        // the repair regardless of how many placeholder rows there are.
        if (DB::table('adjustments')->whereIn('reference', ['ADJ', 'BRK'])->exists()) {
            Artisan::call('adjustments:backfill-references', ['--apply' => true]);
        }

        // The check above only repairs the two known literal placeholder
        // values. Any OTHER duplicate reference (two rows that already
        // share a real generated or manually-edited value, from some
        // unrelated historical data issue) has no deterministic auto-repair
        // policy -- there is no way to know which row's reference is
        // "correct" and which should be renumbered. Rather than let the
        // unique index below fail deployment with an opaque database
        // error, fail loudly here with the exact rows involved so an
        // operator can decide how to resolve them.
        $duplicates = DB::table('adjustments')
            ->select('reference')
            ->groupBy('reference')
            ->havingRaw('count(*) > 1')
            ->pluck('reference');

        if ($duplicates->isNotEmpty()) {
            $details = $duplicates->map(function (string $reference) {
                $ids = DB::table('adjustments')->where('reference', $reference)->pluck('id')->implode(',');

                return "\"{$reference}\" (adjustment ids: {$ids})";
            })->implode('; ');

            throw new \RuntimeException(
                'Cannot add the adjustments.reference unique constraint: duplicate reference(s) found that are not '
                . 'the known ADJ/BRK placeholder (which this migration already repairs automatically). Resolve '
                . 'these manually before re-running this migration -- ' . $details
            );
        }

        Schema::table('adjustments', function (Blueprint $table) {
            $table->unique('reference', 'adjustments_reference_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('adjustments', function (Blueprint $table) {
            $table->dropUnique('adjustments_reference_unique');
        });
    }
};
