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
            $table->foreignId('transfer_id')->constrained('transfers')->cascadeOnDelete();
            $table->foreignId('transfer_route_policy_id')->constrained('transfer_route_policies')->restrictOnDelete();
            $table->foreignId('receipt_movement_id')->constrained('transfer_movements')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('stock_condition', 20); // GOOD, BREAKAGE
            $table->unsignedInteger('required_quantity');
            $table->unsignedInteger('returned_quantity')->default(0);
            $table->string('status', 20)->default('OUTSTANDING'); // OUTSTANDING, FULFILLED
            $table->timestamps();

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
