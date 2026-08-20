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
            $table->decimal('replacement_cost_unit_snapshot', 15, 6)->nullable();
            $table->decimal('replacement_cost_total_snapshot', 15, 2)->nullable();
            $table->string('replacement_cost_snapshot_source')->nullable();
            $table->foreignId('replacement_cost_snapshot_setting_id')->nullable()->constrained('settings')->nullOnDelete();
            $table->timestamp('replacement_cost_snapshot_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dispatch_details', function (Blueprint $table) {
            $table->dropForeign(['replacement_cost_snapshot_setting_id']);
            $table->dropColumn([
                'replacement_cost_unit_snapshot',
                'replacement_cost_total_snapshot',
                'replacement_cost_snapshot_source',
                'replacement_cost_snapshot_setting_id',
                'replacement_cost_snapshot_at',
            ]);
        });
    }
};
