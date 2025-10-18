<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setting_sale_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('setting_id')->constrained('settings')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->timestamps();

            $table->unique('location_id');
            $table->index('setting_id');
        });

        $now = now();
        $existing = DB::table('locations')->select('id as location_id', 'setting_id')->get();

        if ($existing->isNotEmpty()) {
            $payload = $existing->map(function ($row) use ($now) {
                return [
                    'location_id' => $row->location_id,
                    'setting_id'  => $row->setting_id,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
            })->all();

            DB::table('setting_sale_locations')->insert($payload);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('setting_sale_locations');
    }
};
