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
            $table->foreignId('setting_id')->nullable()->after('note')->constrained('settings')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_return_payments', function (Blueprint $table) {
            $table->dropForeign(['setting_id']);
            $table->dropColumn('setting_id');
        });
    }
};
