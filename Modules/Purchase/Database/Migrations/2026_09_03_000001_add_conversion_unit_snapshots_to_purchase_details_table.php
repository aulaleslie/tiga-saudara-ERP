<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_details', function (Blueprint $table) {
            $table->unsignedBigInteger('purchase_unit_id')->nullable()->after('product_id');
            $table->unsignedBigInteger('product_unit_conversion_id')->nullable()->after('purchase_unit_id');
            $table->decimal('entered_quantity', 15, 3)->nullable()->after('quantity');
            $table->decimal('entered_unit_price', 15, 2)->nullable()->after('unit_price');
            $table->decimal('entered_product_discount_amount', 15, 2)->nullable()->after('entered_unit_price');
            $table->decimal('conversion_factor', 12, 6)->nullable()->after('entered_product_discount_amount');
            $table->string('unit_name', 255)->nullable()->after('conversion_factor');
            $table->string('base_unit_name', 255)->nullable()->after('unit_name');

            $table->foreign('purchase_unit_id')->references('id')->on('units')->nullOnDelete();
            $table->foreign('product_unit_conversion_id')->references('id')->on('product_unit_conversions')->nullOnDelete();

            $table->index('purchase_unit_id');
            $table->index('product_unit_conversion_id');
        });

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `purchase_details` MODIFY `unit_price` DECIMAL(15, 6) NOT NULL');
            DB::statement('ALTER TABLE `purchase_details` MODIFY `price` DECIMAL(15, 6) NOT NULL');
        } else {
            Schema::table('purchase_details', function (Blueprint $table) {
                $table->decimal('unit_price', 15, 6)->change();
                $table->decimal('price', 15, 6)->change();
            });
        }

        $this->convertReceivedNoteQuantityReceived('decimal');
    }

    public function down(): void
    {
        $this->assertNoSixDecimalPricesForRollback();
        $this->convertReceivedNoteQuantityReceived('integer');

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `purchase_details` MODIFY `unit_price` DECIMAL(15, 2) NOT NULL');
            DB::statement('ALTER TABLE `purchase_details` MODIFY `price` DECIMAL(15, 2) NOT NULL');
        } else {
            Schema::table('purchase_details', function (Blueprint $table) {
                $table->decimal('unit_price', 15, 2)->change();
                $table->decimal('price', 15, 2)->change();
            });
        }

        Schema::table('purchase_details', function (Blueprint $table) {
            $table->dropForeign(['purchase_unit_id']);
            $table->dropForeign(['product_unit_conversion_id']);

            $table->dropIndex(['purchase_unit_id']);
            $table->dropIndex(['product_unit_conversion_id']);

            $table->dropColumn([
                'purchase_unit_id',
                'product_unit_conversion_id',
                'entered_quantity',
                'entered_unit_price',
                'entered_product_discount_amount',
                'conversion_factor',
                'unit_name',
                'base_unit_name',
            ]);
        });
    }

    private function assertNoSixDecimalPricesForRollback(): void
    {
        $hasSixDecimalPrices = DB::table('purchase_details')
            ->whereRaw('ABS(unit_price - ROUND(unit_price, 2)) > 0.000001 OR ABS(price - ROUND(price, 2)) > 0.000001')
            ->exists();

        if ($hasSixDecimalPrices) {
            throw new \RuntimeException(
                'Cannot rollback migration: high-precision unit_price or price data exists on purchase_details.'
            );
        }
    }

    private function convertReceivedNoteQuantityReceived(string $targetType): void
    {
        if ($targetType === 'integer') {
            $hasFractional = DB::table('received_note_details')
                ->whereRaw('ABS(quantity_received - ROUND(quantity_received)) > 0.0001')
                ->exists();

            if ($hasFractional) {
                throw new \RuntimeException(
                    'Cannot rollback migration: fractional quantity_received data exists on received_note_details.'
                );
            }
        }

        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $sql = $targetType === 'decimal'
                ? 'ALTER TABLE `received_note_details` MODIFY `quantity_received` DECIMAL(15, 3) NOT NULL'
                : 'ALTER TABLE `received_note_details` MODIFY `quantity_received` INT NOT NULL';

            DB::statement($sql);
            return;
        }

        Schema::table('received_note_details', function (Blueprint $table) use ($targetType) {
            if ($targetType === 'decimal') {
                $table->decimal('quantity_received', 15, 3)->change();
            } else {
                $table->integer('quantity_received')->change();
            }
        });
    }
};
