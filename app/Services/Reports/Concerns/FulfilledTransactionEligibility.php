<?php

namespace App\Services\Reports\Concerns;

use Modules\Purchase\Entities\Purchase;
use Modules\Sale\Entities\Sale;

class FulfilledTransactionEligibility
{
    /**
     * Eligible active sale statuses: full dispatch or a partial-return settlement
     * leaving remaining persisted value. `RETURNED` is intentionally excluded here
     * because an unarchived RETURNED sale is handled separately (still reportable
     * until its return settlement is archived).
     */
    public static function eligibleSaleStatuses(): array
    {
        return [Sale::STATUS_DISPATCHED, Sale::STATUS_RETURNED_PARTIALLY];
    }

    /**
     * Eligible active purchase statuses: full receipt or a partial-return settlement
     * leaving remaining persisted value. `RECEIVED PARTIALLY` and every pre-receipt
     * status are excluded.
     */
    public static function eligiblePurchaseStatuses(): array
    {
        return [Purchase::STATUS_RECEIVED, Purchase::STATUS_RETURNED_PARTIALLY];
    }

    /**
     * Raw SQL WHERE fragment for report-eligible sales: eligible active statuses,
     * plus a `RETURNED` status whose full-return settlement is unfinished (the
     * source is not yet archived). A completed full return is archived and excluded.
     *
     * @param  string  $tableAlias  The alias/prefix for the sales table (e.g. 'sales' or 's')
     */
    public static function saleSqlExpression(string $tableAlias = 'sales'): string
    {
        $statuses = implode(', ', array_map(
            fn (string $status) => "'{$status}'",
            self::eligibleSaleStatuses()
        ));

        return "{$tableAlias}.archived_at IS NULL AND ({$tableAlias}.status IN ({$statuses}) OR {$tableAlias}.status = '".Sale::STATUS_RETURNED."')";
    }

    /**
     * Raw SQL WHERE fragment for report-eligible purchases: eligible active statuses,
     * plus a `RETURNED` status whose full-return settlement is unfinished (the
     * source is not yet archived). A completed full return is archived and excluded.
     *
     * @param  string  $tableAlias  The alias/prefix for the purchases table (e.g. 'purchases' or 'p')
     */
    public static function purchaseSqlExpression(string $tableAlias = 'purchases'): string
    {
        $statuses = implode(', ', array_map(
            fn (string $status) => "'{$status}'",
            self::eligiblePurchaseStatuses()
        ));

        return "{$tableAlias}.archived_at IS NULL AND ({$tableAlias}.status IN ({$statuses}) OR {$tableAlias}.status = '".Purchase::STATUS_RETURNED."')";
    }

    /**
     * Apply sale eligibility to a query/Eloquent builder via whereRaw.
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     */
    public static function applyToSaleQuery($query, string $tableAlias = 'sales')
    {
        return $query->whereRaw(self::saleSqlExpression($tableAlias));
    }

    /**
     * Apply purchase eligibility to a query/Eloquent builder via whereRaw.
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     */
    public static function applyToPurchaseQuery($query, string $tableAlias = 'purchases')
    {
        return $query->whereRaw(self::purchaseSqlExpression($tableAlias));
    }
}
