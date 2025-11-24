<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('pos_session_id')->nullable()->after('location_id')->constrained('pos_sessions')->nullOnDelete();
        });

        Schema::table('sale_payments', function (Blueprint $table) {
            $table->foreignId('pos_session_id')->nullable()->after('sale_id')->constrained('pos_sessions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pos_session_id');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pos_session_id');
        });
    }
};
