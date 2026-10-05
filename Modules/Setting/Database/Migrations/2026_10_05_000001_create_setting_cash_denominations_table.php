<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DENOMINATIONS = [
        100,
        200,
        500,
        1_000,
        2_000,
        5_000,
        10_000,
        20_000,
        50_000,
        75_000,
        100_000,
    ];

    public function up(): void
    {
        Schema::create('setting_cash_denominations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('setting_id')->constrained('settings')->cascadeOnDelete();
            $table->unsignedInteger('denomination');
            $table->boolean('is_enabled')->default(true);
            $table->unsignedTinyInteger('position');
            $table->timestamps();

            $table->unique(['setting_id', 'denomination'], 'setting_cash_denomination_unique');
            $table->index(
                ['setting_id', 'is_enabled', 'position'],
                'setting_cash_denomination_status_idx',
            );
        });

        DB::table('settings')
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($settings): void {
                $now = now();
                $rows = [];

                foreach ($settings as $setting) {
                    foreach (self::DENOMINATIONS as $position => $denomination) {
                        $rows[] = [
                            'setting_id' => $setting->id,
                            'denomination' => $denomination,
                            'is_enabled' => true,
                            'position' => $position + 1,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                DB::table('setting_cash_denominations')->insertOrIgnore($rows);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('setting_cash_denominations');
    }
};
