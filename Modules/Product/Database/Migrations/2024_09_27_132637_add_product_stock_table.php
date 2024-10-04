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
        Schema::create('product_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('location_id')->constrained('locations')->onDelete('cascade');
            $table->integer('quantity');
            $table->integer('quantity_non_tax');
            $table->integer('quantity_tax');
            $table->integer('broken_quantity_non_tax');
            $table->integer('broken_quantity_tax');
            $table->decimal('last_purchase_price');
            $table->decimal('average_purchase_price');
            $table->decimal('sale_price');
            $table->foreignId('tax_id')->nullable()->constrained('taxes')->onDelete('set null');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_stocks');
    }
};
