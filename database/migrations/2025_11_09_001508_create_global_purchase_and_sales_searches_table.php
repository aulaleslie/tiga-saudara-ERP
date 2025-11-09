<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Create global_purchase_and_sales_searches audit table to track all global search operations
     * for audit, analytics, and security monitoring purposes. This helps identify
     * usage patterns and maintain compliance requirements.
     */
    public function up(): void
    {
        Schema::create('global_purchase_and_sales_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('setting_id')->constrained('settings')->cascadeOnDelete();
            $table->string('search_query')->nullable();
            $table->string('search_type'); // serial, purchase_ref, sales_ref, supplier, customer, all
            $table->json('transaction_types')->nullable(); // For 'all' searches: ['purchase', 'sale']
            $table->json('filters_applied')->nullable();
            $table->integer('results_count')->default(0);
            $table->integer('response_time_ms')->default(0);
            $table->string('tenant_context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            // Indexes for audit queries
            $table->index('user_id');
            $table->index('setting_id');
            $table->index('search_type');
            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
            $table->index(['setting_id', 'created_at']);
            $table->index(['search_type', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('global_purchase_and_sales_searches');
    }
};
