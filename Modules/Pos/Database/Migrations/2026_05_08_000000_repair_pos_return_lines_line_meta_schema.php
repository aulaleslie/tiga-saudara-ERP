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
        if (! Schema::hasTable('pos_return_lines') || Schema::hasColumn('pos_return_lines', 'line_meta')) {
            return;
        }

        Schema::table('pos_return_lines', function (Blueprint $table) {
            $table->json('line_meta')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('pos_return_lines') || ! Schema::hasColumn('pos_return_lines', 'line_meta')) {
            return;
        }

        Schema::table('pos_return_lines', function (Blueprint $table) {
            $table->dropColumn('line_meta');
        });
    }
};