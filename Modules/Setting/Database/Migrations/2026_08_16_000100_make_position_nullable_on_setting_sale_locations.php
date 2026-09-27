<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Disabled assignments carry no position: the enable/disable refactor writes
     * `position = null` when a location is disabled, but the column was left
     * NOT NULL, so disabling failed on MySQL with a 1048 integrity violation.
     */
    public function up(): void
    {
        Schema::table('setting_sale_locations', function (Blueprint $table) {
            $table->unsignedInteger('position')->nullable()->change();
        });

        // Normalize rows that were disabled before the column allowed null.
        DB::table('setting_sale_locations')
            ->where('is_enabled', false)
            ->whereNotNull('position')
            ->update(['position' => null]);
    }

    /**
     * Reverse the migrations.
     *
     * Restoring NOT NULL requires every row to hold a position, so disabled
     * assignments are appended after the enabled ones per setting.
     */
    public function down(): void
    {
        $settingIds = DB::table('setting_sale_locations')
            ->distinct()
            ->pluck('setting_id');

        foreach ($settingIds as $settingId) {
            $position = (int) DB::table('setting_sale_locations')
                ->where('setting_id', $settingId)
                ->max('position');

            $unpositioned = DB::table('setting_sale_locations')
                ->where('setting_id', $settingId)
                ->whereNull('position')
                ->orderBy('location_id')
                ->pluck('id');

            foreach ($unpositioned as $id) {
                DB::table('setting_sale_locations')
                    ->where('id', $id)
                    ->update(['position' => ++$position]);
            }
        }

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('setting_sale_locations', function (Blueprint $table) {
                $table->unsignedInteger('position')->nullable(false)->change();
            });
        }
    }
};
