<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Modules\Product\Entities\Product;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $affectedProducts = Product::where('stock_managed', 1)
            ->whereNull('base_unit_id')
            ->whereNotNull('unit_id')
            ->with('unit')
            ->get();

        foreach ($affectedProducts as $product) {
            $unit = $product->unit;

            if (!$unit) {
                continue;
            }

            $product->base_unit_id = $product->unit_id;

            if (empty($product->product_unit)) {
                $product->product_unit = $unit->short_name ?: $unit->name;
            }

            $product->save();
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // One-way data migration
    }
};
