<?php

namespace Modules\Adjustment\Services;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Adjustment\Entities\Adjustment;
use Modules\Adjustment\Entities\AdjustmentReferenceSequence;
use RuntimeException;

/**
 * Allocates ADJ-/BRK- reference numbers from a row-locked, uniquely
 * constrained counter table (adjustment_reference_sequences), mirroring the
 * pattern App\Services\Sequence\DocumentSequenceAllocator already uses for
 * Purchase/Sale references. A dedicated table is used here (rather than
 * reusing document_sequences) because that table's schema requires a
 * non-nullable setting_id foreign key, and adjustments carry no
 * setting-scoped reference namespace -- ADJ/BRK numbering is global per
 * prefix/month, matching the pre-existing behavior this replaces.
 *
 * allocate() MUST be called from within the same database transaction that
 * inserts the Adjustment row (Adjustment::boot()'s creating hook does this
 * automatically). The counter row's FOR UPDATE lock is only released when
 * that transaction commits or rolls back, so no other request can allocate
 * the same number until the Adjustment row insert (or its rollback) is
 * final -- unlike a Cache::lock released before the insert happens, there is
 * no window where two concurrent creates can both allocate the same
 * reference before either row becomes visible.
 */
class AdjustmentReferenceService
{
    public const PREFIX_NORMAL = 'ADJ';
    public const PREFIX_BREAKAGE = 'BRK';

    /**
     * The reference prefix namespace for a given adjustment type. Every type
     * other than 'breakage' shares the legacy 'ADJ' namespace, matching the
     * pre-existing behavior for normal adjustments and versioned stock-opname
     * drafts.
     */
    public static function prefixForType(?string $type): string
    {
        return strtolower(trim((string) $type)) === 'breakage'
            ? self::PREFIX_BREAKAGE
            : self::PREFIX_NORMAL;
    }

    /**
     * True when $reference is empty, or is exactly the placeholder value the
     * create forms submit for this prefix ("ADJ" / "BRK"), meaning a real
     * reference still needs to be generated.
     */
    public static function needsGeneration(?string $reference, string $prefix): bool
    {
        $trimmed = trim((string) $reference);

        return $trimmed === '' || strtoupper($trimmed) === strtoupper($prefix);
    }

    /**
     * Atomically allocate the next sequence number within the given
     * prefix/year/month namespace and return the formatted reference.
     *
     * Requires an active database transaction: the counter row is locked
     * FOR UPDATE, reconciled against real historical references (see
     * lockAndReconcileSequenceRow()), and incremented here, staying locked
     * until the caller's transaction ends -- so the Adjustment insert that
     * consumes this reference must happen in the same transaction for the
     * lock to cover both steps.
     *
     * Every call reconciles before incrementing (not just backfill's first
     * call per namespace): this is what makes allocate() itself safe on an
     * installation where the counter table is newly created/empty but the
     * adjustments table already has real numbered references -- without
     * this, a fresh counter row would start at last_number=0 and issue
     * ...00001 again, which the adjustments.reference unique constraint
     * would then reject.
     */
    public static function allocate(string $prefix, ?Carbon $date = null): string
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('AdjustmentReferenceService::allocate() must be called within an active database transaction.');
        }

        $date = $date ?: now();
        $year = (int) $date->year;
        $month = (int) $date->month;

        $row = self::lockAndReconcileSequenceRow($prefix, $year, $month);
        $nextNumber = (int) $row->last_number + 1;

        AdjustmentReferenceSequence::query()
            ->whereKey($row->id)
            ->update(['last_number' => $nextNumber, 'updated_at' => now()]);

        return make_reference_id($prefix, $year, $month, $nextNumber);
    }

    /**
     * Advances the counter to at least the highest numeric suffix already
     * present among real (non-placeholder) references in this namespace,
     * persisting the change (creating the counter row if absent). This
     * DOES mutate the database -- use previewLastNumber() instead for a
     * read-only preview, such as the backfill command's dry-run mode.
     */
    public static function reconcile(string $prefix, int $year, int $month): int
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('AdjustmentReferenceService::reconcile() must be called within an active database transaction.');
        }

        $row = self::lockAndReconcileSequenceRow($prefix, $year, $month);

        return (int) $row->last_number;
    }

    /**
     * Read-only equivalent of reconcile(): returns what the counter's
     * last_number effectively is for this namespace -- the greater of the
     * persisted counter row's last_number (0 if the row does not exist
     * yet) and the true maximum numeric suffix among real references
     * already in the adjustments table -- WITHOUT creating the counter
     * row, locking it, or writing anything. Safe to call outside a
     * transaction and safe to call repeatedly without side effects, which
     * is what makes the backfill command's --dry-run mode genuinely
     * read-only: previewing candidates must never mutate
     * adjustment_reference_sequences, only --apply may.
     */
    public static function previewLastNumber(string $prefix, int $year, int $month): int
    {
        $persisted = (int) (AdjustmentReferenceSequence::query()
            ->where('prefix', $prefix)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->value('last_number') ?? 0);

        $maxHistorical = self::findMaxHistoricalSuffix($prefix, $year, $month);

        return max($persisted, $maxHistorical);
    }

    /**
     * Locks (creating if absent) the counter row for this namespace, then
     * reconciles it up to the true maximum numeric suffix among real
     * references already in the adjustments table if that maximum exceeds
     * the row's current last_number -- covering both a brand-new row
     * (seeded 0, but the namespace may already have real numbered rows
     * predating the counter table) and a pre-existing row that has
     * somehow fallen behind. The row stays locked FOR UPDATE for the
     * remainder of the caller's transaction either way.
     */
    private static function lockAndReconcileSequenceRow(string $prefix, int $year, int $month): AdjustmentReferenceSequence
    {
        $row = AdjustmentReferenceSequence::query()
            ->where('prefix', $prefix)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            try {
                AdjustmentReferenceSequence::query()->create([
                    'prefix' => $prefix,
                    'period_year' => $year,
                    'period_month' => $month,
                    'last_number' => 0,
                ]);
            } catch (QueryException $e) {
                // Another concurrent transaction created the row first; the
                // unique (prefix, period_year, period_month) constraint
                // rejects our insert. Fall through and re-select it with the
                // lock below.
                if (!self::isNamespaceUniqueConflict($e)) {
                    throw $e;
                }
            }

            $row = AdjustmentReferenceSequence::query()
                ->where('prefix', $prefix)
                ->where('period_year', $year)
                ->where('period_month', $month)
                ->lockForUpdate()
                ->firstOrFail();
        }

        $maxHistorical = self::findMaxHistoricalSuffix($prefix, $year, $month);

        if ($maxHistorical > (int) $row->last_number) {
            AdjustmentReferenceSequence::query()
                ->whereKey($row->id)
                ->update(['last_number' => $maxHistorical, 'updated_at' => now()]);

            $row->last_number = $maxHistorical;
        }

        return $row;
    }

    private static function isNamespaceUniqueConflict(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());
        $sqlState = (string) ($e->getCode() ?? '');
        $errorInfo = $e->errorInfo ?? [];
        $driverCode = (int) ($errorInfo[1] ?? 0);

        $isUniqueViolation = ($sqlState === '23000' || $driverCode === 1062 || $driverCode === 19);

        return $isUniqueViolation && str_contains($message, 'adj_ref_seq_prefix_period_unique');
    }

    /**
     * Finds the maximum numeric suffix among real (already-generated)
     * references in this prefix/year/month namespace, ignoring rows that
     * still carry the literal placeholder value.
     */
    private static function findMaxHistoricalSuffix(string $prefix, int $year, int $month): int
    {
        $likePattern = sprintf('%s-%04d-%02d-%%', $prefix, $year, $month);

        $references = Adjustment::query()
            ->where('reference', 'LIKE', $likePattern)
            ->pluck('reference');

        $max = 0;
        foreach ($references as $reference) {
            if (!preg_match('/^(?<prefix>.+)-(?<year>\d{4})-(?<month>\d{2})-(?<number>\d{1,10})$/', (string) $reference, $matches)) {
                continue;
            }

            if ($matches['prefix'] !== $prefix || (int) $matches['year'] !== $year || (int) $matches['month'] !== $month) {
                continue;
            }

            $number = (int) $matches['number'];
            if ($number > $max) {
                $max = $number;
            }
        }

        return $max;
    }
}
