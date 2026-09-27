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
        $tables = ['purchases', 'sales', 'purchase_returns', 'sale_returns'];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->timestamp('archived_at')->nullable();
                $table->unsignedBigInteger('archived_by')->nullable();

                $table->foreign('archived_by')->references('id')->on('users')->onDelete('set null');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = ['purchases', 'sales', 'purchase_returns', 'sale_returns'];

        foreach ($tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropForeign([$table . '_archived_by_foreign']);
                $table->dropColumn(['archived_at', 'archived_by']);
            });
        }
    }
};
