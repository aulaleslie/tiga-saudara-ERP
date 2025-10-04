<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_returns', 'setting_id')) {
                $table->foreignId('setting_id')
                    ->nullable()
                    ->after('supplier_id')
                    ->constrained('settings')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('purchase_returns', 'location_id')) {
                $table->foreignId('location_id')
                    ->nullable()
                    ->after('setting_id')
                    ->constrained('locations')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('purchase_returns', 'cash_proof_path')) {
                $table->string('cash_proof_path')
                    ->nullable()
                    ->after('payment_method');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_returns', 'cash_proof_path')) {
                $table->dropColumn('cash_proof_path');
            }

            if (Schema::hasColumn('purchase_returns', 'location_id')) {
                $table->dropConstrainedForeignId('location_id');
            }

            if (Schema::hasColumn('purchase_returns', 'setting_id')) {
                $table->dropConstrainedForeignId('setting_id');
            }
        });
    }
};
