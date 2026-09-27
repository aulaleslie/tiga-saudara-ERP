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
        Schema::table('product_serial_numbers', function (Blueprint $table) {
            $table->unsignedBigInteger('received_note_detail_id')->nullable()->after('product_id');
            $table->foreign('received_note_detail_id')
                ->references('id')
                ->on('received_note_details')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_serial_numbers', function (Blueprint $table) {
            $table->dropForeign(['received_note_detail_id']);
            $table->dropColumn('received_note_detail_id');
        });
    }
};
