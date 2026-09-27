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
        Schema::create('product_import_rows', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->foreignId('batch_id')->constrained('product_import_batches')->cascadeOnDelete();

            // Original CSV line number (1-based including header? We'll store data-line index)
            $table->unsignedInteger('row_number');

            // Normalized payload for this row (names, prices, conversions, etc.)
            $table->json('raw_json');

            // Per-row results
            $table->enum('status', ['skipped','error','imported'])->nullable();
            $table->text('error_message')->nullable();

            // References created during processing (for UNDO)
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('created_txn_id')->nullable();   // transactions.id
            $table->unsignedBigInteger('created_stock_id')->nullable(); // product_stocks.id

            $table->timestamps();

            // Lookups
            $table->index(['batch_id', 'row_number']);
            $table->index(['status']);
            $table->index(['product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_import_rows');
    }
};
