<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase returns must be able to reverse a decimal canonical quantity now that
 * Purchase lines and receiving support fractional base-unit quantities (see
 * 2026_06_03_000000_convert_quantity_columns_to_decimal.php and
 * 2026_09_03_000001_add_conversion_unit_snapshots_to_purchase_details_table.php).
 * An integer return quantity would silently truncate a fractional entitlement
 * (e.g. 0.688 PCS) and permanently strand it as unreturnable.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->convert('decimal');
    }

    public function down(): void
    {
        $hasFractional = DB::table('purchase_return_details')
            ->whereRaw('ABS(quantity - ROUND(quantity)) > 0.0001')
            ->exists();

        if ($hasFractional) {
            throw new \RuntimeException(
                'Cannot rollback migration: fractional quantity data exists on purchase_return_details.'
            );
        }

        $this->convert('integer');
    }

    private function convert(string $targetType): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $sql = $targetType === 'decimal'
                ? 'ALTER TABLE `purchase_return_details` MODIFY `quantity` DECIMAL(15, 3) NOT NULL'
                : 'ALTER TABLE `purchase_return_details` MODIFY `quantity` INT NOT NULL';

            DB::statement($sql);

            return;
        }

        Schema::table('purchase_return_details', function (Blueprint $table) use ($targetType) {
            if ($targetType === 'decimal') {
                $table->decimal('quantity', 15, 3)->change();
            } else {
                $table->integer('quantity')->change();
            }
        });
    }
};
