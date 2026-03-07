<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            // Add is_available_in_pos if it doesn't exist
            if (!Schema::hasColumn('payment_methods', 'is_available_in_pos')) {
                $table->boolean('is_available_in_pos')->default(false)->after('is_cash');
            }
            // Add requires_reference
            if (!Schema::hasColumn('payment_methods', 'requires_reference')) {
                $table->boolean('requires_reference')->default(false)->after('is_available_in_pos');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('requires_reference');
        });
    }
};
