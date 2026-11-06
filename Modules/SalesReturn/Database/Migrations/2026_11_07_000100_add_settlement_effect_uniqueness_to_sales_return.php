<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: anchors repeatable money effects to the settlement row that caused them, so a
 * replayed approval can never create a second payout/credit. Legacy rows keep NULL anchors
 * (unique indexes ignore NULLs); nothing is backfilled or rewritten.
 */
return new class extends Migration
{
    private const ANCHORED = [
        'sale_return_payments' => 'sale_return_payments_settlement_unique',
        'customer_credits' => 'customer_credits_settlement_unique',
    ];

    public function up(): void
    {
        foreach (self::ANCHORED as $table => $index) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'sale_return_item_settlement_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($index) {
                $blueprint->unsignedBigInteger('sale_return_item_settlement_id')->nullable();
                $blueprint->unique('sale_return_item_settlement_id', $index);
            });
        }

        // One settlement row per (return, detail, serial). Enforced only when existing data
        // already satisfies it; otherwise the locked revalidation remains the guard and an
        // operator resolves duplicates deliberately (history is never rewritten here).
        if (Schema::hasTable('sale_return_item_settlements')) {
            $duplicates = DB::table('sale_return_item_settlements')
                ->whereNotNull('product_serial_number_id')
                ->select('sale_return_id', 'sale_return_detail_id', 'product_serial_number_id')
                ->groupBy('sale_return_id', 'sale_return_detail_id', 'product_serial_number_id')
                ->havingRaw('COUNT(*) > 1')
                ->exists();

            if (! $duplicates) {
                Schema::table('sale_return_item_settlements', function (Blueprint $blueprint) {
                    $blueprint->unique(
                        ['sale_return_id', 'sale_return_detail_id', 'product_serial_number_id'],
                        'sr_item_settlements_line_unique'
                    );
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sale_return_item_settlements')) {
            try {
                Schema::table('sale_return_item_settlements', fn (Blueprint $t) => $t->dropUnique('sr_item_settlements_line_unique'));
            } catch (\Throwable $e) {
                // the index was never created because legacy duplicates existed
            }
        }

        foreach (self::ANCHORED as $table => $index) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'sale_return_item_settlement_id')) {
                Schema::table($table, function (Blueprint $t) use ($index) {
                    $t->dropUnique($index);
                    $t->dropColumn('sale_return_item_settlement_id');
                });
            }
        }
    }
};
