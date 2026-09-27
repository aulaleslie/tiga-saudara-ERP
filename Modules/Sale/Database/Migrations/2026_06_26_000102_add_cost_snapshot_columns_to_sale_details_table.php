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
        Schema::table('sale_details', function (Blueprint $table) {
            $table->decimal('cost_unit_snapshot', 15, 6)->nullable();
            $table->decimal('cost_total_snapshot', 15, 2)->nullable();
            $table->string('cost_snapshot_source')->nullable();
            $table->timestamp('cost_snapshot_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_details', function (Blueprint $table) {
            $table->dropColumn([
                'cost_unit_snapshot',
                'cost_total_snapshot',
                'cost_snapshot_source',
                'cost_snapshot_at',
            ]);
        });
    }
};
