<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Support\\Facades\\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('products')
            ->whereIn('id', function ($subQuery) {
                $subQuery->select('product_id')
                    ->from('product_prices')
                    ->where(function ($priceQuery) {
                        $priceQuery->where('last_purchase_price', '>', 0)
                            ->orWhere('average_purchase_price', '>', 0);
                    });
            })
            ->update(['is_purchased' => true]);

        DB::table('products')
            ->whereIn('id', function ($subQuery) {
                $subQuery->select('product_id')
                    ->from('product_prices')
                    ->where(function ($priceQuery) {
                        $priceQuery->where('sale_price', '>', 0)
                            ->orWhere('tier_1_price', '>', 0)
                            ->orWhere('tier_2_price', '>', 0);
                    });
            })
            ->update(['is_sold' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally left blank - backfilled data should not be reverted automatically.
    }
};
