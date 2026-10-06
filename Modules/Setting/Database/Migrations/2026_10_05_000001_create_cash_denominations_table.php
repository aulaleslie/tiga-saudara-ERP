<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_DENOMINATIONS = [
        ['value' => 100, 'type' => 'coin'],
        ['value' => 200, 'type' => 'coin'],
        ['value' => 500, 'type' => 'coin'],
        ['value' => 1_000, 'type' => 'banknote'],
        ['value' => 2_000, 'type' => 'banknote'],
        ['value' => 5_000, 'type' => 'banknote'],
        ['value' => 10_000, 'type' => 'banknote'],
        ['value' => 20_000, 'type' => 'banknote'],
        ['value' => 50_000, 'type' => 'banknote'],
        ['value' => 75_000, 'type' => 'banknote'],
        ['value' => 100_000, 'type' => 'banknote'],
    ];

    public function up(): void
    {
        Schema::create('cash_denominations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('value')->unique();
            $table->string('type', 20);
            $table->boolean('is_enabled')->default(true);
            $table->unsignedTinyInteger('position');
            $table->timestamps();

            $table->index(['is_enabled', 'position']);
        });

        $now = now();

        DB::table('cash_denominations')->insert(
            array_map(
                static fn (array $denomination, int $position): array => [
                    ...$denomination,
                    'is_enabled' => true,
                    'position' => $position + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                self::DEFAULT_DENOMINATIONS,
                array_keys(self::DEFAULT_DENOMINATIONS),
            ),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_denominations');
    }
};
