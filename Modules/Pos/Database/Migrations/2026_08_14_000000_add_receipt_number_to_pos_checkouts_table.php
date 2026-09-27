<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_checkouts', function (Blueprint $table) {
            $table->string('receipt_number', 60)->nullable()->after('customer_id');
            $table->index('receipt_number', 'pos_checkouts_receipt_number_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pos_checkouts', function (Blueprint $table) {
            $table->dropIndex('pos_checkouts_receipt_number_idx');
            $table->dropColumn('receipt_number');
        });
    }
};
