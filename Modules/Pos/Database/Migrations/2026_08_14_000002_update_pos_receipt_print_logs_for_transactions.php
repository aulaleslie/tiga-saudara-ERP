<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_receipt_print_logs', function (Blueprint $table) {
            // Make pos_checkout_id nullable to support transaction prints
            $table->unsignedBigInteger('pos_checkout_id')->nullable()->change();

            // Add pos_transaction_id column for efficient transaction lookups
            $table->unsignedBigInteger('pos_transaction_id')->nullable()->after('pos_checkout_id');

            // Add foreign key constraint for transaction
            $table->foreign('pos_transaction_id')
                ->references('id')
                ->on('pos_transactions')
                ->onDelete('cascade');

            // Add composite index for fast queries by transaction and print type
            $table->index(['pos_transaction_id', 'print_type'], 'pos_receipt_print_logs_transaction_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pos_receipt_print_logs', function (Blueprint $table) {
            // Drop the foreign key and index
            $table->dropForeign(['pos_transaction_id']);
            $table->dropIndex('pos_receipt_print_logs_transaction_type_idx');

            // Drop the column
            $table->dropColumn('pos_transaction_id');

            // Revert pos_checkout_id to non-nullable
            $table->unsignedBigInteger('pos_checkout_id')->nullable(false)->change();
        });
    }
};
