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
            $table->boolean('is_inventory_managed')->nullable()->after('dispatched_quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dispatch_details', function (Blueprint $table) {
            $table->dropColumn('is_inventory_managed');
        });
    }
};
