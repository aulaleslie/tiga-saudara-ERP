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
        Schema::table('purchase_return_payments', function (Blueprint $table) {
            // Add FK to canonical payment methods
            $table->foreignId('payment_method_id')
                ->nullable()
                ->after('purchase_return_id')
                ->constrained('payment_methods')
                ->cascadeOnDelete();

            $table->index('payment_method_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_return_payments', function (Blueprint $table) {
            $table->dropIndex(['payment_method_id']);
            $table->dropConstrainedForeignId('payment_method_id');
        });
    }
};
