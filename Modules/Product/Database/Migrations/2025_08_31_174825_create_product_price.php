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
        Schema::create('product_prices', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            $table->foreignId('setting_id')
                ->constrained('settings')
                ->cascadeOnDelete();

            // Match existing column types from `products` table (decimal(10,2))
            $table->decimal('sale_price', 10, 2)->nullable();
            $table->decimal('tier_1_price', 10, 2)->nullable();
            $table->decimal('tier_2_price', 10, 2)->nullable();
            $table->decimal('last_purchase_price', 10, 2)->nullable();
            $table->decimal('average_purchase_price', 10, 2)->nullable();

            // Optional tax pointers (aligns with products.purchase_tax / sale_tax -> taxes.id)
            $table->foreignId('purchase_tax_id')->nullable()
                ->constrained('taxes')->nullOnDelete();
            $table->foreignId('sale_tax_id')->nullable()
                ->constrained('taxes')->nullOnDelete();

            $table->timestamps();

            // Enforce: 1 row per product × setting
            $table->unique(['product_id', 'setting_id']);

            // Helpful lookup indexes
            $table->index(['product_id']);
            $table->index(['setting_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_prices');
    }
};
