<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('received_note_detail_id')
                ->nullable()
                ->after('reason');

            // Foreign key with restrict – receiving details must not be deleted
            // while a transaction references them.
            $table->foreign('received_note_detail_id')
                ->references('id')
                ->on('received_note_details')
                ->onDelete('restrict');

            // Nullable unique: only one BUY transaction per receiving detail.
            // Multiple NULLs are allowed by SQL standard (and SQLite/MySQL).
            $table->unique('received_note_detail_id', 'transactions_rnd_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['received_note_detail_id']);
            $table->dropUnique('transactions_rnd_id_unique');
            $table->dropColumn('received_note_detail_id');
        });
    }
};
