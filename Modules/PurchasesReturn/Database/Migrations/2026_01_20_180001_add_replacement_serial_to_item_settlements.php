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
        Schema::table('purchase_return_item_settlements', function (Blueprint $table) {
            $table->unsignedBigInteger('replacement_serial_number_id')->nullable()->after('received_by');
            $table->foreign('replacement_serial_number_id', 'items_settlement_replacement_serial_fk')
                ->references('id')->on('product_serial_numbers')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_return_item_settlements', function (Blueprint $table) {
            $table->dropForeign('items_settlement_replacement_serial_fk');
            $table->dropColumn('replacement_serial_number_id');
        });
    }
};
