<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('merged_into_id')->nullable()->after('canonical_name');
            $table->timestamp('merged_at')->nullable()->after('merged_into_id');

            $table->foreign('merged_into_id')->references('id')->on('products')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['merged_into_id']);
            $table->dropColumn(['merged_into_id', 'merged_at']);
        });
    }
};
