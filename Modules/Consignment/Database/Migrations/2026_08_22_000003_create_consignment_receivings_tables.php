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
        Schema::create('consignment_receivings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consignment_receival_id');
            $table->unsignedBigInteger('setting_id');
            $table->unsignedBigInteger('location_id');
            $table->string('receiving_number', 100);
            $table->string('external_delivery_number', 100)->nullable();
            $table->date('date');
            $table->string('status', 50)->default('PENDING'); // PENDING, APPROVED, REJECTED, REVERSED
            $table->text('note')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();

            $table->foreign('consignment_receival_id')->references('id')->on('consignment_receivals')->onDelete('restrict');
            $table->foreign('setting_id')->references('id')->on('settings')->onDelete('restrict');
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('restrict');
            $table->foreign('received_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('rejected_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reversed_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['setting_id', 'receiving_number'], 'idx_consignment_receivings_setting_num_uniq');
            $table->index(['consignment_receival_id', 'status'], 'idx_cr_receival_status');
            $table->index(['location_id', 'status'], 'idx_cr_location_status');
        });

        Schema::create('consignment_receiving_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consignment_receiving_id');
            $table->foreign('consignment_receiving_id', 'fk_crd_receiving')
                ->references('id')
                ->on('consignment_receivings')
                ->onDelete('cascade');
            $table->unsignedBigInteger('consignment_receival_line_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('quantity_received', 12, 3);
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('unit_dpp', 15, 2);
            $table->unsignedBigInteger('tax_id')->nullable();
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->json('pending_serial_numbers')->nullable();
            $table->decimal('stock_before', 12, 3)->nullable();
            $table->decimal('stock_after', 12, 3)->nullable();
            $table->decimal('stock_tax_before', 12, 3)->nullable();
            $table->decimal('stock_tax_after', 12, 3)->nullable();
            $table->decimal('stock_non_tax_before', 12, 3)->nullable();
            $table->decimal('stock_non_tax_after', 12, 3)->nullable();
            $table->decimal('setting_quantity_before', 12, 3)->nullable();
            $table->decimal('setting_quantity_after', 12, 3)->nullable();
            $table->decimal('setting_avg_cost_before', 15, 2)->nullable();
            $table->decimal('setting_avg_cost_after', 15, 2)->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('reversal_transaction_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('consignment_receival_line_id', 'fk_crd_receival_line')->references('id')->on('consignment_receival_lines')->onDelete('restrict');
            $table->foreign('product_id', 'fk_crd_product')->references('id')->on('products')->onDelete('restrict');
            $table->foreign('tax_id', 'fk_crd_tax')->references('id')->on('taxes')->nullOnDelete();
            $table->foreign('transaction_id', 'fk_crd_transaction')->references('id')->on('transactions')->nullOnDelete();
            $table->foreign('reversal_transaction_id', 'fk_crd_reversal_transaction')->references('id')->on('transactions')->nullOnDelete();

            $table->index(['consignment_receiving_id', 'product_id'], 'idx_crd_receiving_product');
            $table->index(['consignment_receival_line_id'], 'idx_crd_receival_line');
        });

        Schema::create('consignment_receiving_detail_serial_numbers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consignment_receiving_detail_id');
            $table->unsignedBigInteger('product_serial_number_id');
            $table->unsignedBigInteger('source_history_id')->nullable();
            $table->unsignedBigInteger('reversal_history_id')->nullable();
            $table->timestamp('linked_at')->useCurrent();
            $table->timestamps();

            $table->foreign('consignment_receiving_detail_id', 'fk_crd_sn_detail_id')
                ->references('id')
                ->on('consignment_receiving_details')
                ->onDelete('cascade');
            $table->foreign('product_serial_number_id', 'fk_crd_sn_serial_id')
                ->references('id')
                ->on('product_serial_numbers')
                ->onDelete('cascade');
            $table->foreign('source_history_id', 'fk_crd_sn_history_id')
                ->references('id')
                ->on('serial_number_histories')
                ->nullOnDelete();
            $table->foreign('reversal_history_id', 'fk_crd_sn_rev_history_id')
                ->references('id')
                ->on('serial_number_histories')
                ->nullOnDelete();

            $table->unique(['consignment_receiving_detail_id', 'product_serial_number_id'], 'crd_sn_detail_serial_uniq');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consignment_receiving_detail_serial_numbers');
        Schema::dropIfExists('consignment_receiving_details');
        Schema::dropIfExists('consignment_receivings');
    }
};
