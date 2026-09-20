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
        Schema::create('global_pos_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('global_pos_payment_batch_id');
            $table->unsignedBigInteger('pos_transaction_id');
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('sale_payment_id');
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->index('global_pos_payment_batch_id', 'gppa_batch_id_idx');
            $table->index('pos_transaction_id', 'gppa_pos_trx_id_idx');
            $table->index('sale_id', 'gppa_sale_id_idx');
            $table->index('sale_payment_id', 'gppa_sale_payment_id_idx');

            $table->unique('sale_payment_id', 'gppa_sale_payment_unique');

            $table->foreign('global_pos_payment_batch_id', 'gppa_batch_fk')
                ->references('id')->on('global_pos_payment_batches')->onDelete('cascade');
            $table->foreign('pos_transaction_id', 'gppa_pos_trx_fk')
                ->references('id')->on('pos_transactions')->onDelete('cascade');
            $table->foreign('sale_id', 'gppa_sale_fk')
                ->references('id')->on('sales')->onDelete('cascade');
            $table->foreign('sale_payment_id', 'gppa_sale_payment_fk')
                ->references('id')->on('sale_payments')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('global_pos_payment_allocations');
    }
};
