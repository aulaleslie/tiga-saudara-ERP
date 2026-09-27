<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRODUCT_REPAIR and BROKEN_STOCK receipts must accept the same decimal canonical
 * quantity as the return line they settle (see
 * 2026_09_03_000002_convert_purchase_return_details_quantity_to_decimal.php). An
 * integer received_quantity would reject any fractional return, even though the
 * return detail it is confirming receipt against already carries one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->convert('decimal');
    }

    public function down(): void
    {
        $hasFractional = DB::table('purchase_return_item_settlements')
            ->whereNotNull('received_quantity')
            ->whereRaw('ABS(received_quantity - ROUND(received_quantity)) > 0.0001')
            ->exists();

        if ($hasFractional) {
            throw new \RuntimeException(
                'Cannot rollback migration: fractional received_quantity data exists on purchase_return_item_settlements.'
            );
        }

        $this->convert('integer');
    }

    private function convert(string $targetType): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $sql = $targetType === 'decimal'
                ? 'ALTER TABLE `purchase_return_item_settlements` MODIFY `received_quantity` DECIMAL(15, 3) NULL'
                : 'ALTER TABLE `purchase_return_item_settlements` MODIFY `received_quantity` INT NULL';

            DB::statement($sql);

            return;
        }

        Schema::table('purchase_return_item_settlements', function (Blueprint $table) use ($targetType) {
            if ($targetType === 'decimal') {
                $table->decimal('received_quantity', 15, 3)->nullable()->change();
            } else {
                $table->integer('received_quantity')->nullable()->change();
            }
        });
    }
};
