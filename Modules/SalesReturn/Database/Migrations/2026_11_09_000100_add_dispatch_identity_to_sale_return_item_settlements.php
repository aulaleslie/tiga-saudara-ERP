<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persisted dispatch identity for returns held in custody (group 5).
 *
 * dispatch_kind: ORIGINAL (the held goods go back to the customer) or REPLACEMENT (a different
 * unit from available stock goes out; the held original stays in custody). Eligibility and
 * accounting use these columns, never a comparison of serial strings.
 * Additive and nullable: existing rows keep NULL (legacy / not decided).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_return_item_settlements', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_return_item_settlements', 'dispatch_kind')) {
                $table->string('dispatch_kind', 16)->nullable();
            }
            if (! Schema::hasColumn('sale_return_item_settlements', 'dispatch_serial_number_id')) {
                // Outgoing serial (ORIGINAL: the held serial itself; REPLACEMENT: the different unit).
                $table->unsignedBigInteger('dispatch_serial_number_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (DB::table('sale_return_item_settlements')->whereNotNull('dispatch_kind')->exists()) {
            throw new \RuntimeException('Rollback refused: dispatch identity already recorded on settlements. Fix forward instead.');
        }

        Schema::table('sale_return_item_settlements', function (Blueprint $table) {
            $table->dropColumn(['dispatch_kind', 'dispatch_serial_number_id']);
        });
    }
};
