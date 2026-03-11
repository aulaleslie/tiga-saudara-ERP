<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_transaction_line_serials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pos_transaction_line_id');
            $table->string('serial_number', 255);

            $table->unique(['pos_transaction_line_id', 'serial_number'], 'pos_txn_line_serials_unique');
            $table->index('pos_transaction_line_id', 'pos_txn_line_serials_line_idx');

            $table->foreign('pos_transaction_line_id')->references('id')->on('pos_transaction_lines')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_transaction_line_serials');
    }
};
