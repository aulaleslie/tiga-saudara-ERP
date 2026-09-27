<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Mark all existing (pre-feature) sale details as manual_unit_price to preserve their prices.
     * The column defaults to 'automatic' in migration 2026_08_01_000001, so newly created
     * rows during and after deployment will have automatic pricing.
     */
    public function up(): void
    {
        DB::table('sale_details')
            ->where('pricing_source', 'automatic')
            ->update(['pricing_source' => 'manual_unit_price']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('sale_details')
            ->where('pricing_source', 'manual_unit_price')
            ->update(['pricing_source' => 'automatic']);
    }
};
