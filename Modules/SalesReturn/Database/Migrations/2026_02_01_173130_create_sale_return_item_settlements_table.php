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
        Schema::create('sale_return_item_settlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sale_return_id');
            $table->unsignedBigInteger('sale_return_detail_id');
            $table->unsignedBigInteger('product_serial_number_id')->nullable();
            
            $table->string('method')->nullable(); // repair, unprocessed, cash_refund, credit, modify_sale
            $table->decimal('nominal', 15, 2)->nullable();
            $table->unsignedBigInteger('target_sale_id')->nullable();

            // Status and Workflow fields
            $table->string('status')->default('DRAFT');
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->text('approval_note')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->text('rejection_reason')->nullable();
            
            $table->timestamps();

            $table->foreign('sale_return_id', 'sr_items_settlement_sr_foreign')->references('id')->on('sale_returns')->onDelete('cascade');
            $table->foreign('sale_return_detail_id', 'sr_items_settlement_srd_foreign')->references('id')->on('sale_return_details')->onDelete('cascade');
            $table->foreign('product_serial_number_id', 'sr_items_settlement_sn_foreign')->references('id')->on('product_serial_numbers')->onDelete('set null');
            $table->foreign('target_sale_id', 'sr_items_settlement_ts_foreign')->references('id')->on('sales')->onDelete('set null');
            $table->foreign('submitted_by', 'sr_items_settlement_sub_by_foreign')->references('id')->on('users')->onDelete('set null');
            $table->foreign('approved_by', 'sr_items_settlement_app_by_foreign')->references('id')->on('users')->onDelete('set null');
            $table->foreign('rejected_by', 'sr_items_settlement_rej_by_foreign')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_return_item_settlements');
    }
};
