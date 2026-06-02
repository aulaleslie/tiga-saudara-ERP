<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Convert quantity columns from integer to decimal so fractional, weight-based quantities
 * (e.g. 23.7 KG) survive persistence. Integer columns silently truncated/rounded these on
 * MySQL/MariaDB even though SQLite tolerated them, leaving imported detail/stock quantities
 * inconsistent with the reconciled invoice total.
 *
 * decimal(15, 3) keeps three fractional digits (matching common weight units) with ample
 * headroom for large counts.
 */
return new class extends Migration
{
    /**
     * Map of table => quantity columns to convert.
     *
     * @var array<string, array<int, string>>
     */
    private array $columns = [
        'purchase_details' => ['quantity'],
        'sale_details' => ['quantity'],
        'products' => ['product_quantity'],
        'product_stocks' => [
            'quantity', 'quantity_tax', 'quantity_non_tax',
            'broken_quantity', 'broken_quantity_tax', 'broken_quantity_non_tax',
        ],
        'transactions' => [
            'quantity', 'current_quantity', 'broken_quantity',
            'previous_quantity', 'after_quantity',
            'previous_quantity_at_location', 'after_quantity_at_location',
            'quantity_non_tax', 'quantity_tax',
            'broken_quantity_non_tax', 'broken_quantity_tax',
        ],
    ];

    public function up(): void
    {
        $this->convert(fn (Blueprint $table, string $column, bool $nullable) => $nullable
            ? $table->decimal($column, 15, 3)->nullable()->change()
            : $table->decimal($column, 15, 3)->change());
    }

    public function down(): void
    {
        $this->convert(fn (Blueprint $table, string $column, bool $nullable) => $nullable
            ? $table->integer($column)->nullable()->change()
            : $table->integer($column)->change());
    }

    private function convert(callable $apply): void
    {
        foreach ($this->columns as $tableName => $columns) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            $present = array_values(array_filter(
                $columns,
                fn (string $column): bool => Schema::hasColumn($tableName, $column)
            ));

            if ($present === []) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($present, $tableName, $apply) {
                foreach ($present as $column) {
                    $nullable = $this->isNullable($tableName, $column);
                    $apply($table, $column, $nullable);
                }
            });
        }
    }

    private function isNullable(string $tableName, string $column): bool
    {
        $definition = Schema::getConnection()
            ->getDoctrineColumn($tableName, $column);

        return ! $definition->getNotnull();
    }
};
