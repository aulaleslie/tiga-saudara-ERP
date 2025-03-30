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
            $table->unsignedBigInteger('dispatch_detail_id')->nullable()->after('product_id');
            $table->foreign('dispatch_detail_id')
                ->references('id')
                ->on('dispatch_details')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_serial_numbers', function (Blueprint $table) {
            $table->dropForeign(['dispatch_detail_id']);
            $table->dropColumn('dispatch_detail_id');
        });
    }
};
