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
        Schema::table('sale_payments', function (Blueprint $table) {
            // Multi-stage payment tracking
            $table->unsignedInteger('stage_order')->nullable()->after('reference');
            $table->string('edc_reference')->nullable()->after('stage_order');
            $table->string('idempotency_key')->nullable()->after('edc_reference')->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['stage_order', 'edc_reference', 'idempotency_key']);
        });
    }
};
