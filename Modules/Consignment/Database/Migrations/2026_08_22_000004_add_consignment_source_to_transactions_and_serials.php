<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('consignment_receiving_detail_id')
                ->nullable()
                ->after('received_note_detail_id');

            $table->foreign('consignment_receiving_detail_id', 'fk_transactions_crd_id')
                ->references('id')
                ->on('consignment_receiving_details')
                ->onDelete('restrict');

            $table->index('consignment_receiving_detail_id', 'idx_transactions_crd_id');
        });

        Schema::table('product_serial_numbers', function (Blueprint $table) {
            $table->unsignedBigInteger('consignment_receiving_detail_id')
                ->nullable()
                ->after('received_note_detail_id');

            $table->foreign('consignment_receiving_detail_id', 'fk_psn_crd_id')
                ->references('id')
                ->on('consignment_receiving_details')
                ->nullOnDelete();

            $table->index('consignment_receiving_detail_id', 'idx_psn_crd_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_serial_numbers', function (Blueprint $table) {
            $table->dropForeign('fk_psn_crd_id');
            $table->dropIndex('idx_psn_crd_id');
            $table->dropColumn('consignment_receiving_detail_id');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign('fk_transactions_crd_id');
            $table->dropIndex('idx_transactions_crd_id');
            $table->dropColumn('consignment_receiving_detail_id');
        });
    }
};
