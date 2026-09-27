<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
     * The metadata avoids Doctrine column introspection. This migration must run in production
     * installs where doctrine/dbal may be absent because it is a dev dependency.
     *
     * @var array<string, array<string, array{nullable: bool, default?: int}>>
     */
    private array $columns = [
        'purchase_details' => [
            'quantity' => ['nullable' => false],
        ],
        'sale_details' => [
            'quantity' => ['nullable' => false],
        ],
        'products' => [
            'product_quantity' => ['nullable' => false, 'default' => 0],
        ],
        'product_stocks' => [
            'quantity' => ['nullable' => false],
            'quantity_tax' => ['nullable' => false],
            'quantity_non_tax' => ['nullable' => false],
            'broken_quantity' => ['nullable' => false],
            'broken_quantity_tax' => ['nullable' => false],
            'broken_quantity_non_tax' => ['nullable' => false],
        ],
        'transactions' => [
            'quantity' => ['nullable' => false],
            'current_quantity' => ['nullable' => false],
            'broken_quantity' => ['nullable' => true],
            'previous_quantity' => ['nullable' => false],
            'after_quantity' => ['nullable' => false],
            'previous_quantity_at_location' => ['nullable' => false],
            'after_quantity_at_location' => ['nullable' => false],
            'quantity_non_tax' => ['nullable' => false],
            'quantity_tax' => ['nullable' => false],
            'broken_quantity_non_tax' => ['nullable' => false],
            'broken_quantity_tax' => ['nullable' => false],
        ],
    ];

    public function up(): void
    {
        $this->convert('decimal');
    }

    public function down(): void
    {
        $this->convert('integer');
    }

    /**
     * Re-declare each column with the new type while preserving known nullability and defaults.
     * MySQL/MariaDB use raw ALTER statements so production migrations do not depend on DBAL.
     */
    private function convert(string $targetType): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->convertMysql($targetType);

            return;
        }

        $this->convertWithSchemaBuilder($targetType);
    }

    private function convertMysql(string $targetType): void
    {
        $typeSql = $targetType === 'decimal' ? 'DECIMAL(15, 3)' : 'INT';

        foreach ($this->columns as $tableName => $columnDefinitions) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            foreach ($columnDefinitions as $column => $metadata) {
                if (! Schema::hasColumn($tableName, $column)) {
                    continue;
                }

                DB::statement(sprintf(
                    'ALTER TABLE `%s` MODIFY `%s` %s %s%s',
                    str_replace('`', '``', $tableName),
                    str_replace('`', '``', $column),
                    $typeSql,
                    $metadata['nullable'] ? 'NULL' : 'NOT NULL',
                    array_key_exists('default', $metadata) ? ' DEFAULT '.$metadata['default'] : ''
                ));
            }
        }
    }

    private function convertWithSchemaBuilder(string $targetType): void
    {
        foreach ($this->columns as $tableName => $columnDefinitions) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($columnDefinitions, $targetType, $tableName) {
                foreach ($columnDefinitions as $column => $metadata) {
                    if (! Schema::hasColumn($tableName, $column)) {
                        continue;
                    }

                    $columnDefinition = $targetType === 'decimal'
                        ? $table->decimal($column, 15, 3)
                        : $table->integer($column);

                    if ($metadata['nullable']) {
                        $columnDefinition->nullable();
                    }

                    if (array_key_exists('default', $metadata)) {
                        $columnDefinition->default($metadata['default']);
                    }

                    $columnDefinition->change();
                }
            });
        }
    }
};
