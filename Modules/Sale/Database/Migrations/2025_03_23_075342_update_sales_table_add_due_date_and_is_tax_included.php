<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Add due_date (nullable date) after 'date'
            $table->date('due_date')->nullable()->after('date');
            // Add is_tax_included as a boolean flag (default false) after due_date
            $table->boolean('is_tax_included')->default(false)->after('due_date');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('due_date');
            $table->dropColumn('is_tax_included');
        });
    }
};
