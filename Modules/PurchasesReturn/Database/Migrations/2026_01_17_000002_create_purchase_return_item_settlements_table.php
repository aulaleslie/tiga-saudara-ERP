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
        Schema::dropIfExists('purchase_return_item_settlements');
        Schema::create('purchase_return_item_settlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_return_id');
            $table->unsignedBigInteger('purchase_return_detail_id');
            $table->unsignedBigInteger('product_serial_number_id')->nullable();
            
            $table->string('method'); // product_repair, broken_stock, modify_purchase, credit, cash
            $table->decimal('nominal', 15, 2)->nullable();
            $table->unsignedBigInteger('target_purchase_id')->nullable();
            
            $table->timestamps();

            $table->foreign('purchase_return_id', 'items_settlement_pr_foreign')->references('id')->on('purchase_returns')->onDelete('cascade');
            $table->foreign('purchase_return_detail_id', 'items_settlement_prd_foreign')->references('id')->on('purchase_return_details')->onDelete('cascade');
            $table->foreign('product_serial_number_id', 'items_settlement_sn_foreign')->references('id')->on('product_serial_numbers')->onDelete('set null');
            $table->foreign('target_purchase_id', 'items_settlement_tp_foreign')->references('id')->on('purchases')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_return_item_settlements');
    }
};
