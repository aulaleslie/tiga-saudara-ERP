<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('setting_sale_locations', function (Blueprint $table) {
            $table->unsignedInteger('position')->nullable()->after('is_enabled');
        });

        // Backfill deterministic order per setting_id
        $settings = DB::table('settings')->pluck('id');

        foreach ($settings as $settingId) {
            $assignments = DB::table('setting_sale_locations')
                ->join('locations', 'setting_sale_locations.location_id', '=', 'locations.id')
                ->where('setting_sale_locations.setting_id', $settingId)
                ->orderByRaw('CASE WHEN locations.setting_id = ? THEN 0 ELSE 1 END', [$settingId])
                ->orderBy('locations.name')
                ->orderBy('locations.id')
                ->select('setting_sale_locations.id')
                ->get();

            $position = 1;
            foreach ($assignments as $assignment) {
                DB::table('setting_sale_locations')
                    ->where('id', $assignment->id)
                    ->update(['position' => $position++]);
            }
        }

        Schema::table('setting_sale_locations', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlite') {
                $table->unsignedInteger('position')->nullable(false)->change();
            }
            $table->index(['setting_id', 'is_enabled', 'position', 'location_id'], 'idx_setting_sale_locations_resolver_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('setting_sale_locations', function (Blueprint $table) {
            $table->dropIndex('idx_setting_sale_locations_resolver_order');
            $table->dropColumn('position');
        });
    }
};
