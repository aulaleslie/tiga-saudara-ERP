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
            $table->foreignId('component_sale_bundle_item_id')->nullable()->after('dispatch_detail_id')
                ->constrained('sale_bundle_items')->nullOnDelete();
            $table->string('cost_origin')->nullable();
            $table->decimal('cost_unit_snapshot', 15, 6)->nullable();
            $table->decimal('cost_quantity', 15, 4)->nullable();
            $table->decimal('cost_total_snapshot', 15, 2)->nullable();
            $table->string('cost_snapshot_source')->nullable();
            $table->foreignId('cost_snapshot_setting_id')->nullable()->constrained('settings')->nullOnDelete();
            $table->boolean('cost_snapshot_setting_is_pkp')->nullable();
            $table->timestamp('cost_snapshot_at')->nullable();
            $table->timestamp('cost_effective_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_return_details', function (Blueprint $table) {
            $table->dropForeign(['component_sale_bundle_item_id']);
            $table->dropForeign(['cost_snapshot_setting_id']);
            $table->dropColumn([
                'component_sale_bundle_item_id',
                'cost_origin',
                'cost_unit_snapshot',
                'cost_quantity',
                'cost_total_snapshot',
                'cost_snapshot_source',
                'cost_snapshot_setting_id',
                'cost_snapshot_setting_is_pkp',
                'cost_snapshot_at',
                'cost_effective_at',
            ]);
        });
    }
};
