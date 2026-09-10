<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Services\AdjustmentReferenceService;

class BackfillAdjustmentReferences extends Command
{
    protected $signature = 'adjustments:backfill-references
        {--apply : Persist changes instead of dry-run}
        {--adjustment-id=* : Limit to specific adjustment IDs}';

    protected $description = 'Detect and optionally repair adjustments whose reference is still the literal ADJ/BRK placeholder instead of a generated document number';

    /**
     * @var array<string, int> Per-namespace simulated last_number, seeded
     *                          from AdjustmentReferenceService::previewLastNumber()
     *                          (a read-only lookup) and advanced locally
     *                          for each candidate previewed in this
     *                          dry-run pass. Never written back to the
     *                          database, so a --dry-run invocation never
     *                          mutates adjustment_reference_sequences.
     */
    private array $dryRunCounters = [];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $adjustmentIds = $this->sanitizeIds($this->option('adjustment-id'));

        $stats = ['scanned' => 0, 'updated' => 0];

        $this->line('Mode: '.($apply ? 'apply' : 'dry-run'));

        $query = Adjustment::query()->orderBy('created_at')->orderBy('id');

        if ($adjustmentIds !== []) {
            $query->whereIn('id', $adjustmentIds);
        } else {
            // Every reference this command can repair is one of the two
            // literal placeholder values the create forms submit -- a real
            // generated reference always carries a "-YYYY-MM-NNNNN" suffix,
            // so it can never collide with this filter.
            $query->whereIn('reference', [
                AdjustmentReferenceService::PREFIX_NORMAL,
                AdjustmentReferenceService::PREFIX_BREAKAGE,
            ]);
        }

        // Processed in created_at/id order (oldest first) so the backfilled
        // sequence numbers reflect the actual historical creation order
        // within each prefix/month namespace, not an arbitrary scan order
        // (a legacy row can have a lower id than an already-numbered row
        // from the same month if it was created later in wall-clock time
        // but is being repaired first, or vice versa -- ordering by
        // created_at keeps the assigned sequence meaningful either way).
        $query->chunkById(200, function ($adjustments) use ($apply, &$stats) {
            foreach ($adjustments as $adjustment) {
                $stats['scanned']++;

                $prefix = AdjustmentReferenceService::prefixForType($adjustment->type);

                if (!AdjustmentReferenceService::needsGeneration($adjustment->reference, $prefix)) {
                    continue;
                }

                $oldReference = (string) $adjustment->reference;

                if (!$apply) {
                    $newReference = $this->previewNextReference($prefix, $adjustment->created_at);

                    $this->line(sprintf(
                        'CAND  adjustment#%d type=%s reference=%s => %s',
                        $adjustment->id,
                        $adjustment->type ?? 'normal',
                        $oldReference,
                        $newReference
                    ));
                    continue;
                }

                // Each row's allocation + save happens in its own
                // transaction: allocate() locks and increments the counter
                // row FOR UPDATE, and that lock is held until this
                // transaction commits, so no concurrent create() for the
                // same namespace can allocate the same number while this
                // repair is in flight.
                $newReference = DB::transaction(function () use ($adjustment, $prefix) {
                    $reference = AdjustmentReferenceService::allocate($prefix, $adjustment->created_at);
                    $adjustment->reference = $reference;
                    $adjustment->saveQuietly();

                    return $reference;
                });

                $stats['updated']++;

                $this->line(sprintf(
                    'FIXED adjustment#%d %s => %s',
                    $adjustment->id,
                    $oldReference,
                    $newReference
                ));
            }
        });

        $this->newLine();
        $this->info('Summary');
        $this->line('Scanned: '.$stats['scanned']);
        $this->line('Updated: '.$stats['updated']);

        if (!$apply && $stats['scanned'] > 0) {
            $this->comment('Dry-run only. Re-run with --apply to persist.');
        }

        return self::SUCCESS;
    }

    /**
     * Simulates the next reference `allocate()` would assign for this
     * namespace, without mutating the real counter. The simulated counter
     * is seeded once per namespace via previewLastNumber() -- a read-only
     * lookup that neither creates nor locks nor updates the counter row --
     * and then advanced in memory for every subsequent candidate in the
     * same namespace during this dry-run pass, so multiple legacy rows
     * sharing a namespace are previewed with distinct, correctly
     * incrementing numbers instead of all reporting the same "next"
     * value, while a plain `adjustments:backfill-references` (no --apply)
     * genuinely makes zero writes to adjustment_reference_sequences.
     */
    private function previewNextReference(string $prefix, \DateTimeInterface $date): string
    {
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n');
        $key = "{$prefix}:{$year}-{$month}";

        if (!array_key_exists($key, $this->dryRunCounters)) {
            $this->dryRunCounters[$key] = AdjustmentReferenceService::previewLastNumber($prefix, $year, $month);
        }

        $this->dryRunCounters[$key]++;

        return make_reference_id($prefix, $year, $month, $this->dryRunCounters[$key]);
    }

    /**
     * @param  mixed  $raw
     * @return array<int, int>
     */
    private function sanitizeIds($raw): array
    {
        $values = is_array($raw) ? $raw : [$raw];

        return array_values(array_unique(array_filter(array_map(static function ($value) {
            if ($value === null || $value === '') {
                return null;
            }

            return max(0, (int) $value);
        }, $values))));
    }
}
