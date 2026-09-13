<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_movement_return_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id');
            $table->foreignId('transfer_route_policy_id');
            $table->foreignId('receipt_movement_id');
            $table->foreignId('product_id');
            $table->string('stock_condition', 20); // GOOD, BREAKAGE
            $table->unsignedInteger('required_quantity');
            $table->unsignedInteger('returned_quantity')->default(0);
            $table->string('status', 20)->default('OUTSTANDING'); // OUTSTANDING, FULFILLED
            $table->timestamps();

            $table->foreign('transfer_id', 'tmro_transfer_fk')
                ->references('id')->on('transfers')->cascadeOnDelete();
            $table->foreign('transfer_route_policy_id', 'tmro_route_policy_fk')
                ->references('id')->on('transfer_route_policies')->restrictOnDelete();
            $table->foreign('receipt_movement_id', 'tmro_receipt_movement_fk')
                ->references('id')->on('transfer_movements')->restrictOnDelete();
            $table->foreign('product_id', 'tmro_product_fk')
                ->references('id')->on('products')->restrictOnDelete();

            $table->unique(
                ['transfer_id', 'receipt_movement_id', 'product_id', 'stock_condition'],
                'transfer_mvmt_return_obligations_identity_unique'
            );
            $table->index(['transfer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_movement_return_obligations');
    }
};
