<?php

namespace App\Services\Reports\Concerns;

use Illuminate\Support\Facades\DB;

class EffectivePurchaseReportingDate
{
    /**
     * Return the fully-qualified SQL expression for effective purchase reporting date:
     * resolves active `reporting_date` before original `date`, compatible with MySQL/MariaDB and SQLite.
     * Wrapped in DATE() for consistent date comparison across database platforms.
     *
     * @param  string  $tableAlias  The alias/prefix for the purchases table (e.g. 'purchases' or 'p')
     * @return string The raw SQL expression
     */
    public static function sqlExpression(string $tableAlias = 'purchases'): string
    {
        return "DATE(COALESCE({$tableAlias}.reporting_date, {$tableAlias}.date))";
    }
}
