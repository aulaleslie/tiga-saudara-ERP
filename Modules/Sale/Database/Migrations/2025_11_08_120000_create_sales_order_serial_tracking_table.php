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
        Schema::create('sales_order_serial_tracking', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('product_serial_number_id')->constrained('product_serial_numbers')->cascadeOnDelete();
            $table->integer('quantity_allocated')->default(1);
            $table->dateTime('dispatch_date')->nullable();
            $table->dateTime('return_date')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index('sale_id');
            $table->index('product_serial_number_id');
            $table->index('created_at');
            
            // Composite index for efficient queries (using unique index instead of constraint to avoid name length issues)
            $table->unique(['sale_id', 'product_serial_number_id'], 'unique_sale_serial');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_order_serial_tracking');
    }
};
