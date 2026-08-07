<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('canonical_name')->nullable()->unique();
        });

        // Backfill canonical names for unambiguous legacy products
        $canonicalizer = new \Modules\Product\Services\ProductCanonicalizer();
        
        $keyCounts = [];
        $productKeys = [];

        // First pass: identify collision groups
        \Illuminate\Support\Facades\DB::table('products')->orderBy('id')->chunk(100, function ($products) use ($canonicalizer, &$keyCounts, &$productKeys) {
            foreach ($products as $product) {
                try {
                    $identity = $canonicalizer->canonicalize((string)$product->product_name);
                    $key = $identity['canonical_key'];
                    $keyCounts[$key] = ($keyCounts[$key] ?? 0) + 1;
                    $productKeys[$product->id] = $key;
                } catch (\Exception $e) {
                    // Blank names or unparseable names remain NULL
                }
            }
        });

        // Second pass: backfill ONLY unambiguous keys
        foreach ($productKeys as $id => $key) {
            if ($keyCounts[$key] === 1) {
                \Illuminate\Support\Facades\DB::table('products')
                    ->where('id', $id)
                    ->update(['canonical_name' => $key]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('canonical_name');
        });
    }
};
