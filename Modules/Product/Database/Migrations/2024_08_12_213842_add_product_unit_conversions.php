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
        Schema::create('product_unit_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('unit_id')->constrained('units')->onDelete('cascade');  // e.g., "Pack"
            $table->foreignId('base_unit_id')->constrained('units')->onDelete('cascade');  // e.g., "Piece"
            $table->decimal('conversion_factor', 10, 2);  // e.g., "1 Pack = 24 Pieces"
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_unit_conversions');
    }
};
