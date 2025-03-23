<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('sale_bundle_items', function (Blueprint $table) {
            $table->id();
            // Link to sale details (each bundle item belongs to one sale detail)
            $table->unsignedBigInteger('sale_detail_id');
            // Optionally, also store sale_id for easier reference (redundant, but convenient)
            $table->unsignedBigInteger('sale_id');
            // Bundle identifiers if applicable
            $table->unsignedBigInteger('bundle_id');
            $table->unsignedBigInteger('bundle_item_id');
            // Product info for the bundled item
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('name');
            // Price (use integer if working with minor currency units, or decimal if needed)
            $table->integer('price');
            // Base quantity from the bundle (can be multiplied by parent quantity in recalculations)
            $table->integer('quantity');
            // Calculated subtotal (price * computed quantity)
            $table->integer('sub_total');
            $table->timestamps();

            $table->foreign('sale_detail_id')
                ->references('id')->on('sale_details')
                ->cascadeOnDelete();
            $table->foreign('sale_id')
                ->references('id')->on('sales')
                ->cascadeOnDelete();
            $table->foreign('product_id')
                ->references('id')->on('products')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_bundle_items');
    }
};
