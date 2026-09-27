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
        Schema::table('dispatch_details', function (Blueprint $table) {
            $table->unsignedBigInteger('sale_detail_id')->nullable()->after('sale_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dispatch_details', function (Blueprint $table) {
            $table->dropColumn('sale_detail_id');
        });
    }
};
