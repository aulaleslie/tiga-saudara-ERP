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
        $duplicates = DB::table('consignment_receival_lines')
            ->select('consignment_receival_id', 'product_id', DB::raw('COUNT(*) as line_count'))
            ->groupBy('consignment_receival_id', 'product_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $affectedReceivalIds = $duplicates->pluck('consignment_receival_id')->unique()->implode(', ');
            throw new \RuntimeException(
                "Migration aborted: Found duplicate product lines in consignment_receival_lines for consignment_receival_id: [{$affectedReceivalIds}]. Clean up duplicate lines before applying unique constraint."
            );
        }

        Schema::table('consignment_receival_lines', function (Blueprint $table) {
            $table->unique(['consignment_receival_id', 'product_id'], 'idx_crl_receival_product_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('consignment_receival_lines', function (Blueprint $table) {
            $table->dropUnique('idx_crl_receival_product_unique');
        });
    }
};
