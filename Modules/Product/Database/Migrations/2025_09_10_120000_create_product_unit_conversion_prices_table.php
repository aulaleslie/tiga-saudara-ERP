<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_unit_conversion_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_unit_conversion_id')
                ->constrained('product_unit_conversions')
                ->cascadeOnDelete();
            $table->foreignId('setting_id')
                ->constrained('settings')
                ->cascadeOnDelete();
            $table->decimal('price', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['product_unit_conversion_id', 'setting_id'], 'conversion_setting_unique');
        });

        $settingIds = DB::table('settings')->pluck('id')->all();
        if (empty($settingIds)) {
            $settingIds = DB::table('products')
                ->distinct()
                ->whereNotNull('setting_id')
                ->pluck('setting_id')
                ->all();
        }

        if (!empty($settingIds)) {
            DB::table('product_unit_conversions')
                ->select(['id', 'price'])
                ->orderBy('id')
                ->chunkById(500, function ($conversions) use ($settingIds) {
                    $timestamp = now();
                    $rows = [];

                    foreach ($conversions as $conversion) {
                        foreach ($settingIds as $settingId) {
                            $rows[] = [
                                'product_unit_conversion_id' => $conversion->id,
                                'setting_id'                 => (int) $settingId,
                                'price'                      => $conversion->price ?? 0,
                                'created_at'                 => $timestamp,
                                'updated_at'                 => $timestamp,
                            ];
                        }
                    }

                    if (!empty($rows)) {
                        DB::table('product_unit_conversion_prices')->insert($rows);
                    }
                });
        }

        Schema::table('product_unit_conversions', function (Blueprint $table) {
            if (Schema::hasColumn('product_unit_conversions', 'price')) {
                $table->dropColumn('price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_unit_conversions', function (Blueprint $table) {
            if (!Schema::hasColumn('product_unit_conversions', 'price')) {
                $table->decimal('price', 15, 2)->default(0)->after('conversion_factor');
            }
        });

        $priceRows = DB::table('product_unit_conversion_prices')
            ->select('product_unit_conversion_id', DB::raw('MAX(price) as price'))
            ->groupBy('product_unit_conversion_id')
            ->get();

        foreach ($priceRows as $row) {
            DB::table('product_unit_conversions')
                ->where('id', $row->product_unit_conversion_id)
                ->update(['price' => $row->price ?? 0]);
        }

        Schema::dropIfExists('product_unit_conversion_prices');
    }
};
