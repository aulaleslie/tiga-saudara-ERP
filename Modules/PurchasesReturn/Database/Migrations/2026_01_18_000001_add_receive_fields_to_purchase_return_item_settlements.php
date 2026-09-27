<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add receive tracking fields for PRODUCT_REPAIR and BROKEN_STOCK settlement methods.
     */
    public function up(): void
    {
        Schema::table('purchase_return_item_settlements', function (Blueprint $table) {
            // Add receive tracking fields
            $table->integer('received_quantity')->nullable()->after('rejection_reason');
            $table->unsignedBigInteger('received_location_id')->nullable()->after('received_quantity');
            $table->text('received_note')->nullable()->after('received_location_id');
            $table->timestamp('received_at')->nullable()->after('received_note');
            $table->unsignedBigInteger('received_by')->nullable()->after('received_at');

            // Add foreign keys
            $table->foreign('received_location_id', 'items_settlement_recv_loc_foreign')
                ->references('id')->on('locations')->onDelete('set null');
            $table->foreign('received_by', 'items_settlement_recv_by_foreign')
                ->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_return_item_settlements', function (Blueprint $table) {
            $table->dropForeign('items_settlement_recv_loc_foreign');
            $table->dropForeign('items_settlement_recv_by_foreign');

            $table->dropColumn([
                'received_quantity',
                'received_location_id',
                'received_note',
                'received_at',
                'received_by',
            ]);
        });
    }
};