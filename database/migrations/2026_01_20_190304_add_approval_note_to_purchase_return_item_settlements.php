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
        if (!Schema::hasColumn('purchase_return_item_settlements', 'approval_note')) {
            Schema::table('purchase_return_item_settlements', function (Blueprint $table) {
                $table->text('approval_note')->nullable()->after('approved_by');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('purchase_return_item_settlements', 'approval_note')) {
            Schema::table('purchase_return_item_settlements', function (Blueprint $table) {
                $table->dropColumn('approval_note');
            });
        }
    }
};
