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
        Schema::table('transactions', function (Blueprint $table) {
            // Drop the enum column
            $table->dropColumn('type');
        });

        Schema::table('transactions', function (Blueprint $table) {
            // Recreate the column as a string with controlled length
            $table->string('type', 4)->comment('Type of transaction');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('type');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->enum('type', ['ADJ', 'SELL', 'BUY', 'TRF'])->comment('Type of transaction');
        });
    }
};
