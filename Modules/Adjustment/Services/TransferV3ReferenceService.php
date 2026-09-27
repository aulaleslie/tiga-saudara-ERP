<?php

namespace Modules\Adjustment\Services;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Adjustment\Entities\AdjustmentReferenceSequence;
use RuntimeException;

/**
 * Allocates globally unique, location-independent Stock Transfer version 3
 * references (TSM-YYYY-MM-NNNN) from the row-locked counter table already
 * used for adjustment references. Legacy TS- numbers are never touched: the
 * TSM prefix is a separate namespace, and uniqueness is enforced by the
 * nullable transfers.v3_reference_key unique index rather than the legacy
 * (origin_location_id, document_number) key, which cannot protect rows whose
 * origin is NULL.
 *
 * Must run inside the transaction that inserts the transfer, so the counter
 * row lock covers the insert.
 */
class TransferV3ReferenceService
{
    public const PREFIX = 'TSM';

    public static function allocate(?Carbon $date = null): string
    {
        $resolver = function () use ($date) {
            $date = $date ?: now();
            $year = (int) $date->year;
            $month = (int) $date->month;

            $row = self::lockSequenceRow($year, $month);

            $next = max((int) $row->last_number, self::maxExistingSuffix($year, $month)) + 1;

            AdjustmentReferenceSequence::query()
                ->whereKey($row->id)
                ->update(['last_number' => $next, 'updated_at' => now()]);

            return sprintf('%s-%04d-%02d-%04d', self::PREFIX, $year, $month, $next);
        };

        if (DB::transactionLevel() === 0) {
            return DB::transaction($resolver);
        }

        return $resolver();
    }

    private static function lockSequenceRow(int $year, int $month): AdjustmentReferenceSequence
    {
        $query = fn () => AdjustmentReferenceSequence::query()
            ->where('prefix', self::PREFIX)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->lockForUpdate()
            ->first();

        $row = $query();

        if ($row === null) {
            try {
                AdjustmentReferenceSequence::query()->create([
                    'prefix'       => self::PREFIX,
                    'period_year'  => $year,
                    'period_month' => $month,
                    'last_number'  => 0,
                ]);
            } catch (QueryException $e) {
                // A concurrent transaction created the namespace row first;
                // the unique (prefix, period) index rejected ours. Re-read it.
            }

            $row = $query();
        }

        if ($row === null) {
            throw new RuntimeException('Unable to allocate a stock transfer reference.');
        }

        return $row;
    }

    /**
     * Reconciles the counter against references already stored, so a
     * counter row that is missing or behind never reissues a number.
     */
    private static function maxExistingSuffix(int $year, int $month): int
    {
        $prefix = sprintf('%s-%04d-%02d-', self::PREFIX, $year, $month);

        $max = 0;
        foreach (DB::table('transfers')->where('v3_reference_key', 'like', $prefix . '%')->pluck('v3_reference_key') as $reference) {
            if (preg_match('/-(\d+)$/', (string) $reference, $matches)) {
                $max = max($max, (int) $matches[1]);
            }
        }

        return $max;
    }
}
