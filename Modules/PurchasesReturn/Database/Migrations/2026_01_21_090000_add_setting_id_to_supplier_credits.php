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
        Schema::table('supplier_credits', function (Blueprint $table) {
            if (!Schema::hasColumn('supplier_credits', 'setting_id')) {
                $table->unsignedBigInteger('setting_id')->nullable()->after('status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplier_credits', function (Blueprint $table) {
            if (Schema::hasColumn('supplier_credits', 'setting_id')) {
                $table->dropColumn('setting_id');
            }
        });
    }
};
