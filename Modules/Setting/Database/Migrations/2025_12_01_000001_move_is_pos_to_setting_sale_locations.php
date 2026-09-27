<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('setting_sale_locations', function (Blueprint $table) {
            $table->boolean('is_pos')
                ->nullable()
                ->default(false)
                ->after('location_id')
                ->comment('Flag to mark if this location is used for POS');
        });

        DB::table('locations')
            ->select(['id', 'is_pos'])
            ->orderBy('id')
            ->lazy()
            ->each(function ($location) {
                DB::table('setting_sale_locations')
                    ->where('location_id', $location->id)
                    ->update(['is_pos' => (bool) $location->is_pos]);
            });

        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('is_pos');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->boolean('is_pos')
                ->default(false)
                ->after('name')
                ->comment('Flag to mark if this location is used for POS');
        });

        DB::table('setting_sale_locations')
            ->select(['location_id', 'is_pos'])
            ->orderBy('location_id')
            ->lazy()
            ->each(function ($assignment) {
                DB::table('locations')
                    ->where('id', $assignment->location_id)
                    ->update(['is_pos' => (bool) $assignment->is_pos]);
            });

        Schema::table('setting_sale_locations', function (Blueprint $table) {
            $table->dropColumn('is_pos');
        });
    }
};
