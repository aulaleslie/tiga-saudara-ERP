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
        Schema::table('product_serial_numbers', function (Blueprint $table) {
            // Drop the old global unique index
            $table->dropUnique(['serial_number']);
            
            // Add the new composite unique index
            $table->unique(['product_id', 'serial_number']);

            // Add new return lifecycle columns
            $table->boolean('is_in_return_process')->default(false)->after('status');
            $table->foreignId('purchase_return_id')->nullable()->constrained('purchase_returns')->onDelete('set null')->after('is_in_return_process');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_serial_numbers', function (Blueprint $table) {
            $table->dropForeign(['purchase_return_id']);
            $table->dropColumn(['purchase_return_id', 'is_in_return_process']);

            // Revert indexes (warning: this might fail if duplicates were introduced during the up state)
            $table->dropUnique(['product_id', 'serial_number']);
            $table->unique('serial_number');
        });
    }
};
