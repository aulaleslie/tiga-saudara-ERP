<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive, nullable lineage from a generated Sale detail to the customer-facing
     * POS transaction line it was posted from. Historical and non-POS rows stay null.
     */
    public function up(): void
    {
        Schema::table('sale_details', function (Blueprint $table) {
            $table->unsignedBigInteger('pos_transaction_line_id')->nullable()->after('sale_id');
            $table->index('pos_transaction_line_id', 'sale_details_pos_transaction_line_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('sale_details', function (Blueprint $table) {
            $table->dropIndex('sale_details_pos_transaction_line_id_index');
            $table->dropColumn('pos_transaction_line_id');
        });
    }
};
