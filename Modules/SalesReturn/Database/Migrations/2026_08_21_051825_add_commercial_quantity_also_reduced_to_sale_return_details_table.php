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
        Schema::table('sale_return_details', function (Blueprint $table) {
            $table->boolean('commercial_quantity_also_reduced')->nullable()->after('cost_effective_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_return_details', function (Blueprint $table) {
            $table->dropColumn('commercial_quantity_also_reduced');
        });
    }
};
