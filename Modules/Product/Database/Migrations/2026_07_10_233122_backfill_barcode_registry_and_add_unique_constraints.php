<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $service = new \Modules\Product\Services\BarcodePreflightService();
        $results = $service->detectDuplicates();
        
        if (!empty($results['conflicts']) || !empty($results['invalid'])) {
            throw new \Exception("Invalid or duplicate barcodes found. Cannot migrate. Preflight results: " . json_encode($results));
        }
        
        // Backfill products
        \Illuminate\Support\Facades\DB::table('products')
            ->whereNotNull('barcode')
            ->where('barcode', '!=', '')
            ->orderBy('id')
            ->chunk(500, function ($products) {
                $inserts = [];
                foreach ($products as $p) {
                    $key = \Modules\Product\Utils\BarcodeUtils::canonicalize($p->barcode);
                    if ($key) {
                        $inserts[] = [
                            'canonical_key' => $key,
                            'value' => $p->barcode,
                            'product_id' => $p->id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }
                if (!empty($inserts)) {
                    $keys = array_column($inserts, 'canonical_key');
                    $existing = \Illuminate\Support\Facades\DB::table('barcode_identities')
                        ->whereIn('canonical_key', $keys)
                        ->get()
                        ->keyBy('canonical_key');

                    $toInsert = [];
                    foreach ($inserts as $insert) {
                        $key = $insert['canonical_key'];
                        if ($existing->has($key)) {
                            $ex = $existing->get($key);
                            if ($ex->product_id != $insert['product_id'] || $ex->value !== $insert['value']) {
                                throw new \Exception("Restart failed: identity mismatch for canonical_key '{$key}' (existing product_id: {$ex->product_id}, new product_id: {$insert['product_id']})");
                            }
                        } else {
                            $toInsert[] = $insert;
                        }
                    }
                    if (!empty($toInsert)) {
                        \Illuminate\Support\Facades\DB::table('barcode_identities')->insert($toInsert);
                    }
                }
            });
            
        // Backfill conversions
        \Illuminate\Support\Facades\DB::table('product_unit_conversions')
            ->whereNotNull('barcode')
            ->where('barcode', '!=', '')
            ->orderBy('id')
            ->chunk(500, function ($conversions) {
                $inserts = [];
                foreach ($conversions as $c) {
                    $key = \Modules\Product\Utils\BarcodeUtils::canonicalize($c->barcode);
                    if ($key) {
                        $inserts[] = [
                            'canonical_key' => $key,
                            'value' => $c->barcode,
                            'product_unit_conversion_id' => $c->id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                }
                if (!empty($inserts)) {
                    $keys = array_column($inserts, 'canonical_key');
                    $existing = \Illuminate\Support\Facades\DB::table('barcode_identities')
                        ->whereIn('canonical_key', $keys)
                        ->get()
                        ->keyBy('canonical_key');

                    $toInsert = [];
                    foreach ($inserts as $insert) {
                        $key = $insert['canonical_key'];
                        if ($existing->has($key)) {
                            $ex = $existing->get($key);
                            if ($ex->product_unit_conversion_id != $insert['product_unit_conversion_id'] || $ex->value !== $insert['value']) {
                                throw new \Exception("Restart failed: identity mismatch for canonical_key '{$key}' (existing conversion_id: {$ex->product_unit_conversion_id}, new conversion_id: {$insert['product_unit_conversion_id']})");
                            }
                        } else {
                            $toInsert[] = $insert;
                        }
                    }
                    if (!empty($toInsert)) {
                        \Illuminate\Support\Facades\DB::table('barcode_identities')->insert($toInsert);
                    }
                }
            });

        // Add unique indexes safely
        $sm = Schema::getConnection()->getDoctrineSchemaManager();
        
        $productIndexes = $sm->listTableIndexes('products');
        if (!array_key_exists('products_barcode_unique', $productIndexes)) {
            Schema::table('products', function (Blueprint $table) {
                $table->unique('barcode');
            });
        }
        
        $conversionIndexes = $sm->listTableIndexes('product_unit_conversions');
        if (!array_key_exists('product_unit_conversions_barcode_unique', $conversionIndexes)) {
            Schema::table('product_unit_conversions', function (Blueprint $table) {
                $table->unique('barcode');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('product_unit_conversions', function (Blueprint $table) {
            $table->dropUnique(['barcode']);
        });
        
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['barcode']);
        });
        
        \Illuminate\Support\Facades\DB::table('barcode_identities')->truncate();
    }
};
