<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable financial adjustment evidence for standard cash returns (group 6).
 *
 * One row per approved CASH_REFUND settlement (unique): committed return value, the part that
 * reduced the customer's debt, the part actually paid out in cash (linked payout), and the
 * source fingerprint the reviewer saw. Additive: no existing row is changed or backfilled; sales
 * and returns approved before this change simply have no adjustment rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sale_return_adjustments')) {
            Schema::create('sale_return_adjustments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sale_return_item_settlement_id')->constrained('sale_return_item_settlements')->restrictOnDelete();
                $table->unsignedBigInteger('sale_return_id');
                $table->unsignedBigInteger('sale_id');
                $table->unsignedBigInteger('sale_detail_id')->nullable();
                $table->unsignedBigInteger('sale_return_detail_id');
                $table->unsignedBigInteger('product_serial_number_id')->nullable();
                $table->decimal('quantity', 15, 3);
                // committed_value = debt_reduction + cash_refund (all >= 0)
                $table->decimal('committed_value', 15, 2);
                $table->decimal('debt_reduction', 15, 2)->default(0);
                $table->decimal('cash_refund', 15, 2)->default(0);
                $table->string('source_fingerprint', 64);
                $table->unsignedSmallInteger('policy_version');
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->timestamp('committed_at');
                // Linked payout; NULL for debt-only / zero-charge returns (no cash-payment row exists).
                $table->unsignedBigInteger('sale_return_payment_id')->nullable();
                $table->timestamps();

                $table->unique('sale_return_item_settlement_id', 'sr_adjustments_settlement_unique');
                $table->index('sale_id', 'sr_adjustments_sale_idx');
                $table->index(['sale_id', 'sale_detail_id'], 'sr_adjustments_line_idx');
            });
        }

        Schema::table('sale_return_item_settlements', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_return_item_settlements', 'reviewed_value')) {
                $table->decimal('reviewed_value', 15, 2)->nullable();
            }
            if (! Schema::hasColumn('sale_return_item_settlements', 'reviewed_fingerprint')) {
                $table->string('reviewed_fingerprint', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('sale_return_adjustments') && DB::table('sale_return_adjustments')->exists()) {
            throw new \RuntimeException('Rollback refused: sale_return_adjustments already holds financial evidence. Disable new standard settlements and fix forward instead.');
        }

        Schema::dropIfExists('sale_return_adjustments');

        Schema::table('sale_return_item_settlements', function (Blueprint $table) {
            $table->dropColumn(['reviewed_value', 'reviewed_fingerprint']);
        });
    }
};
