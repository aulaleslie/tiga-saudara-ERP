<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('setting_sale_locations', function (Blueprint $table) {
            $table->unsignedInteger('position')
                ->nullable()
                ->after('is_pos');
        });

        $counters = [];

        DB::table('setting_sale_locations')
            ->select(['id', 'setting_id'])
            ->orderBy('setting_id')
            ->orderBy('id')
            ->lazy()
            ->each(function ($assignment) use (&$counters) {
                $settingId = (int) $assignment->setting_id;
                $counters[$settingId] = ($counters[$settingId] ?? 0) + 1;

                DB::table('setting_sale_locations')
                    ->where('id', $assignment->id)
                    ->update(['position' => $counters[$settingId]]);
            });

        DB::statement('ALTER TABLE setting_sale_locations MODIFY position INT UNSIGNED NOT NULL');
    }

    public function down(): void
    {
        Schema::table('setting_sale_locations', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
