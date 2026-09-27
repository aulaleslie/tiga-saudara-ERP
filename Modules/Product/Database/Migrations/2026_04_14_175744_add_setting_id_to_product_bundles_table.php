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
        Schema::table('product_bundles', function (Blueprint $table) {
            $table->unsignedBigInteger('setting_id')->nullable()->after('id');
            $table->foreign('setting_id')->references('id')->on('settings')->onDelete('cascade');
        });

        // Backfill existing bundles by duplicating them to all settings
        $bundles = DB::table('product_bundles')->whereNull('setting_id')->get();
        $settings = DB::table('settings')->get();

        foreach ($bundles as $bundle) {
            foreach ($settings as $setting) {
                // Duplicate bundle header
                $newBundleId = DB::table('product_bundles')->insertGetId([
                    'setting_id' => $setting->id,
                    'parent_product_id' => $bundle->parent_product_id,
                    'name' => $bundle->name,
                    'description' => $bundle->description,
                    'price' => $bundle->price,
                    'active_from' => $bundle->active_from,
                    'active_to' => $bundle->active_to,
                    'created_at' => $bundle->created_at,
                    'updated_at' => $bundle->updated_at,
                ]);

                // Duplicate items for this bundle
                $items = DB::table('product_bundle_items')->where('bundle_id', $bundle->id)->get();
                foreach ($items as $item) {
                    DB::table('product_bundle_items')->insert([
                        'bundle_id' => $newBundleId,
                        'product_id' => $item->product_id,
                        'price' => $item->price,
                        'quantity' => $item->quantity,
                        'created_at' => $item->created_at,
                        'updated_at' => $item->updated_at,
                    ]);
                }
            }
            // Delete original bundle (this will also delete original items via cascade)
            DB::table('product_bundles')->where('id', $bundle->id)->delete();
        }

        Schema::table('product_bundles', function (Blueprint $table) {
            $table->unsignedBigInteger('setting_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_bundles', function (Blueprint $table) {
            $table->dropForeign(['setting_id']);
            $table->dropColumn('setting_id');
        });
    }
};
