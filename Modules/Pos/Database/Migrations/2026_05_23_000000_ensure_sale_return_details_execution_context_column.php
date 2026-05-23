<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sale_return_details')) {
            return;
        }

        Schema::table('sale_return_details', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_return_details', 'execution_context')) {
                $table->json('execution_context')->nullable()->after('stock_behavior');
            }
        });
    }

    public function down(): void
    {
    }
};