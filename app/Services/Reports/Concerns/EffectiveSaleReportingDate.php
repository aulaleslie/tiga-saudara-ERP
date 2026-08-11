<?php

namespace App\Services\Reports\Concerns;

class EffectiveSaleReportingDate
{
    /**
     * Return the fully-qualified SQL expression for effective sale reporting date:
     * resolves active `reporting_date` before original `date`, compatible with MySQL/MariaDB and SQLite.
     * Wrapped in DATE() for consistent date comparison across database platforms.
     *
     * @param  string  $tableAlias  The alias/prefix for the sales table (e.g. 'sales' or 's')
     * @return string The raw SQL expression
     */
    public static function sqlExpression(string $tableAlias = 'sales'): string
    {
        return "DATE(COALESCE({$tableAlias}.reporting_date, {$tableAlias}.date))";
    }
}
