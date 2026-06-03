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
        Schema::table('sales_import_rows', function (Blueprint $table) {
            $table->index(['batch_id', 'status', 'row_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_import_rows', function (Blueprint $table) {
            $table->dropIndex(['batch_id', 'status', 'row_number']);
        });
    }
};
