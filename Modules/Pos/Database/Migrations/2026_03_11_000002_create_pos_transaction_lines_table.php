<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_transaction_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pos_transaction_id');
            $table->unsignedSmallInteger('line_no');
            $table->unsignedBigInteger('product_id');
            $table->string('product_name_snapshot', 255);
            $table->string('product_code_snapshot', 100)->nullable();
            $table->unsignedBigInteger('conversion_id')->nullable();
            $table->unsignedInteger('qty');
            $table->decimal('unit_price', 15, 2);
            $table->unsignedBigInteger('tax_id')->nullable();
            $table->string('tax_name_snapshot', 100)->nullable();
            $table->decimal('tax_rate_snapshot', 6, 4)->default(0);
            $table->string('line_discount_type', 20)->default('fixed');
            $table->decimal('line_discount_value', 15, 2)->default(0);
            $table->json('line_meta')->nullable();
            $table->timestamps();

            $table->unique(['pos_transaction_id', 'line_no'], 'pos_txn_lines_txn_lineno_unique');
            $table->index('pos_transaction_id', 'pos_txn_lines_txn_idx');

            $table->foreign('pos_transaction_id')->references('id')->on('pos_transactions')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_transaction_lines');
    }
};
