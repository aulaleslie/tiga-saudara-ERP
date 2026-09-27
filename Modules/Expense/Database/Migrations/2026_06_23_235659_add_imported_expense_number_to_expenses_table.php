<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('imported_expense_number')->nullable()->after('setting_id');
            // Create a unique constraint for imported_expense_number scoped by setting_id
            $table->unique(['setting_id', 'imported_expense_number'], 'expenses_imported_expense_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropUnique('expenses_imported_expense_number_unique');
            $table->dropColumn('imported_expense_number');
        });
    }
};
