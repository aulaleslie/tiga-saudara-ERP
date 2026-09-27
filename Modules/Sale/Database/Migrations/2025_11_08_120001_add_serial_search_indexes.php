<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Add indexes for serial number search performance optimization:
     * - Index on product_serial_numbers.serial_number for fast lookups
     * - Composite index on sales table for efficient filtering
     * - Index on sale_details for relationship queries
     * - Index on product_serial_numbers.location_id for tenant scoping
     */
    public function up(): void
    {
        // Add index on serial_number for fast searches
        Schema::table('product_serial_numbers', function (Blueprint $table) {
            $table->index('serial_number');
            $table->index('location_id');
        });

        // Add composite index on sales for common filter combinations
        Schema::table('sales', function (Blueprint $table) {
            $table->index(['reference', 'status', 'created_at']);
            $table->index('status');
            $table->index('created_at');
        });

        // Add indexes to sale_details for efficient joins
        Schema::table('sale_details', function (Blueprint $table) {
            $table->index('sale_id');
            $table->index('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_serial_numbers', function (Blueprint $table) {
            $table->dropIndex(['serial_number']);
            $table->dropIndex(['location_id']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['reference', 'status', 'created_at']);
            $table->dropIndex(['status']);
            $table->dropIndex(['created_at']);
        });

        Schema::table('sale_details', function (Blueprint $table) {
            $table->dropIndex(['sale_id']);
            $table->dropIndex(['product_id']);
        });
    }
};
