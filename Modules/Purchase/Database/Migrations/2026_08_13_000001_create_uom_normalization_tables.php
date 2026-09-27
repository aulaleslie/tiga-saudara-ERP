<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Normalization batch header: one product, one conversion, one execution
        Schema::create('uom_normalization_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('setting_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_unit_conversion_id');
            $table->unsignedBigInteger('actor_user_id');
            $table->string('status')->default('PENDING'); // PENDING, EXECUTED
            $table->text('reason')->nullable();

            // Conversion snapshot (immutable after execution)
            $table->unsignedBigInteger('source_unit_id');
            $table->unsignedBigInteger('base_unit_id');
            $table->decimal('conversion_factor', 12, 6);

            // Execution audit
            $table->timestamp('executed_at')->nullable();
            $table->json('cost_outcome')->nullable(); // before/after HPP data

            $table->timestamps();

            $table->foreign('setting_id')->references('id')->on('settings')->onDelete('restrict');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('restrict');
            $table->foreign('product_unit_conversion_id')->references('id')->on('product_unit_conversions')->onDelete('restrict');
            $table->foreign('actor_user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('source_unit_id')->references('id')->on('units')->onDelete('restrict');
            $table->foreign('base_unit_id')->references('id')->on('units')->onDelete('restrict');

            $table->index('setting_id');
            $table->index('product_id');
            $table->index('actor_user_id');
            $table->index('status');
            $table->index('created_at');
        });

        // Selected purchase/receipt lines for the normalization batch
        Schema::create('uom_normalization_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('batch_id');
            $table->unsignedBigInteger('purchase_detail_id');
            $table->unsignedBigInteger('received_note_detail_id');
            $table->unsignedBigInteger('transaction_id')->nullable(); // matched BUY transaction

            // Source snapshot (before normalization)
            $table->decimal('source_quantity', 14, 3);
            $table->decimal('source_sub_total', 14, 2)->nullable();
            $table->decimal('source_unit_price', 14, 2)->nullable();
            $table->decimal('source_tax_amount', 14, 2)->nullable();

            // Normalized result (after normalization)
            $table->decimal('normalized_quantity', 14, 3)->nullable();
            $table->decimal('normalized_unit_cost', 14, 6)->nullable();

            // Transaction snapshot (before/after)
            $table->json('transaction_snapshot_before')->nullable();
            $table->json('transaction_snapshot_after')->nullable();

            $table->timestamps();

            $table->foreign('batch_id')->references('id')->on('uom_normalization_batches')->onDelete('cascade');
            $table->foreign('purchase_detail_id')->references('id')->on('purchase_details')->onDelete('restrict');
            $table->foreign('received_note_detail_id')->references('id')->on('received_note_details')->onDelete('restrict');
            $table->foreign('transaction_id')->references('id')->on('transactions')->onDelete('restrict');

            $table->index('batch_id');
            $table->index('purchase_detail_id');
            $table->index('received_note_detail_id');

            // Uniqueness guards: a receiving detail can only be normalized once
            $table->unique('received_note_detail_id', 'uom_norm_lines_rnd_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uom_normalization_lines');
        Schema::dropIfExists('uom_normalization_batches');
    }
};
