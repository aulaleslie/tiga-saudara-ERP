<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add database indexes to improve search performance for global purchase and sales search.
     * These indexes target frequently searched columns and should provide significant
     * performance improvements for large datasets.
     */
    public function up(): void
    {
        // Purchase table indexes - Note: All required indexes already exist from previous migrations
        // Schema::table('purchases', function (Blueprint $table) {
        //     $table->index('reference'); // Already exists: purchases_reference_index
        //     $table->index('supplier_id'); // Already exists: purchases_supplier_id_index
        //     $table->index('created_at'); // Already exists: purchases_created_at_index
        //     $table->index('setting_id'); // Already exists: purchases_setting_id_index
        //     $table->index(['setting_id', 'reference']); // Already exists: purchases_setting_id_reference_index
        //     $table->index(['setting_id', 'supplier_id']); // Already exists: purchases_setting_id_supplier_id_index
        //     $table->index(['setting_id', 'created_at']); // Already exists: purchases_setting_id_created_at_index
        // });

        // Sales table indexes
        Schema::table('sales', function (Blueprint $table) {
            // Note: reference, customer_id, and created_at indexes already exist
            $table->index('setting_id'); // New index for tenant filtering
            $table->index(['setting_id', 'reference']); // New composite for tenant + reference
            $table->index(['setting_id', 'customer_id']); // New composite for tenant + customer
            $table->index(['setting_id', 'created_at']); // New composite for tenant + date filtering
        });

        // Product serial numbers indexes
        Schema::table('product_serial_numbers', function (Blueprint $table) {
            // Note: serial_number index already exists from previous migration
            // The received_note_detail_id foreign key index already provides indexing
            // No additional indexes needed for this table
        });

        // JSON serial numbers for sales (MySQL 8.0+)
        if (DB::getDriverName() === 'mysql') {
            DB::statement('
                ALTER TABLE dispatch_details
                ADD INDEX idx_dispatch_details_serial_numbers
                ((CAST(serial_numbers AS CHAR(255))))
            ');
        }
    }

    /**
     * Reverse the migrations.
     *
     * Remove the search indexes. This should be done carefully as it may impact
     * performance if the global search feature is still in use.
     */
    public function down(): void
    {
        // Remove JSON index for sales serial numbers
        if (DB::getDriverName() === 'mysql') {
            DB::statement('
                ALTER TABLE dispatch_details
                DROP INDEX idx_dispatch_details_serial_numbers
            ');
        }

        // Remove product serial numbers indexes
        Schema::table('product_serial_numbers', function (Blueprint $table) {
            // Note: No indexes to drop as none were created by this migration
            // (existing indexes were created by other migrations)
        });

        // Remove sales table indexes (only the ones created by this migration)
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['setting_id', 'created_at']); // Created by this migration
            $table->dropIndex(['setting_id', 'customer_id']); // Created by this migration
            $table->dropIndex(['setting_id', 'reference']); // Created by this migration
            $table->dropIndex(['setting_id']); // Created by this migration
            // Note: reference, customer_id, and created_at indexes not dropped as they were created by other migrations
        });

        // Remove purchase table indexes - Note: Indexes not dropped as they were created by other migrations
        // Schema::table('purchases', function (Blueprint $table) {
        //     $table->dropIndex(['setting_id', 'created_at']); // Created by other migration
        //     $table->dropIndex(['setting_id', 'supplier_id']); // Created by other migration
        //     $table->dropIndex(['setting_id', 'reference']); // Created by other migration
        //     $table->dropIndex(['setting_id']); // Created by other migration
        //     $table->dropIndex(['created_at']); // Created by other migration
        //     $table->dropIndex(['supplier_id']); // Created by other migration
        //     $table->dropIndex(['reference']); // Created by other migration
        // });
    }
};
