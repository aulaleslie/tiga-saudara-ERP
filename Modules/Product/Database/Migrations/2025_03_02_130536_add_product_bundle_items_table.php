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
        Schema::create('product_bundle_items', function (Blueprint $table) {
            $table->id();
            // Reference to the bundle header
            $table->unsignedBigInteger('bundle_id');
            // The product included in this bundle
            $table->unsignedBigInteger('product_id');
            // Price override for this product in the bundle (optional)
            $table->decimal('price', 10, 2)->nullable();
            // Quantity of this product in the bundle (default 1)
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();

            $table->foreign('bundle_id')
                ->references('id')
                ->on('product_bundles')
                ->onDelete('cascade');
            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_bundle_items');
    }
};
