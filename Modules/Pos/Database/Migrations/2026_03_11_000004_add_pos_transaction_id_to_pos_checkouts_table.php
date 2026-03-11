<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_checkouts') || Schema::hasColumn('pos_checkouts', 'pos_transaction_id')) {
            return;
        }

        Schema::table('pos_checkouts', function (Blueprint $table) {
            $table->unsignedBigInteger('pos_transaction_id')->nullable()->after('id');
            $table->foreign('pos_transaction_id')->references('id')->on('pos_transactions')->nullOnDelete();
            $table->index('pos_transaction_id', 'pos_checkouts_transaction_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_checkouts') || ! Schema::hasColumn('pos_checkouts', 'pos_transaction_id')) {
            return;
        }

        Schema::table('pos_checkouts', function (Blueprint $table) {
            $table->dropForeign(['pos_transaction_id']);
            $table->dropIndex('pos_checkouts_transaction_idx');
            $table->dropColumn('pos_transaction_id');
        });
    }
};
