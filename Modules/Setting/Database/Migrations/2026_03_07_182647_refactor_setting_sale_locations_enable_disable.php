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
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['location_id']);
            }
            $table->dropUnique(['location_id']);
        });

        Schema::table('setting_sale_locations', function (Blueprint $table) {
            $table->boolean('is_enabled')->default(true)->after('location_id');
        });

        Schema::table('setting_sale_locations', function (Blueprint $table) {
            $table->unique(['setting_id', 'location_id']);
            $table->index(['setting_id', 'is_enabled', 'location_id']);
            $table->dropColumn('position');

            if (DB::getDriverName() !== 'sqlite') {
                $table->foreign('location_id')->references('id')->on('locations')->cascadeOnDelete();
            }
        });

        // Backfill full matrix
        $now = now();
        $settings = DB::table('settings')->pluck('id');
        $locations = DB::table('locations')->pluck('id');

        $chunks = $settings->crossJoin($locations)->chunk(500);
        foreach ($chunks as $chunk) {
            DB::table('setting_sale_locations')->insertOrIgnore(
                $chunk->map(fn ($pair) => [
                    'setting_id'  => $pair[0],
                    'location_id' => $pair[1],
                    'is_enabled'  => true,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ])->all()
            );
        }

        // Verification assertions
        $settingsCount = $settings->count();
        $locationsCount = $locations->count();
        $expectedCount = $settingsCount * $locationsCount;
        $actualCount = DB::table('setting_sale_locations')->count();

        if ($actualCount !== $expectedCount) {
            throw new \RuntimeException("Migration backfill mismatch! Expected $expectedCount rows, found $actualCount.");
        }

        $nullsCount = DB::table('setting_sale_locations')->whereNull('is_enabled')->count();
        if ($nullsCount > 0) {
            throw new \RuntimeException("Migration backfill mismatch! Found $nullsCount rows with null is_enabled.");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Delete matrix-backfilled rows FIRST before restoring unique constraint.
        // Easiest heuristic: Delete all rows where setting_id != locations.setting_id.
        // This is safe because the original behavior kept (or generated) rows with the owner setting anyway.
        DB::table('setting_sale_locations')
            ->join('locations', 'setting_sale_locations.location_id', '=', 'locations.id')
            ->whereColumn('setting_sale_locations.setting_id', '!=', 'locations.setting_id')
            ->delete();

        Schema::table('setting_sale_locations', function (Blueprint $table) {
            $table->dropUnique(['setting_id', 'location_id']);
            $table->dropIndex(['setting_id', 'is_enabled', 'location_id']);
            $table->dropColumn('is_enabled');
            $table->unsignedInteger('position')->nullable()->after('location_id');
            $table->unique(['location_id']);
        });

        // Restore positions sequentially based on original assignments.
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

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE setting_sale_locations MODIFY position INT UNSIGNED NOT NULL');
        }
    }
};
