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
        if (!Schema::hasColumn('sale_return_item_settlements', 'dispatch_note')) {
            Schema::table('sale_return_item_settlements', function (Blueprint $table) {
                $table->text('dispatch_note')->nullable()->after('dispatch_rejection_reason');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_return_item_settlements', function (Blueprint $table) {
            $table->dropColumn('dispatch_note');
        });
    }
};
