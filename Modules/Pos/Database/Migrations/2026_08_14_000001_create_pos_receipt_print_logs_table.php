<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_receipt_print_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('setting_id');
            $table->unsignedBigInteger('pos_checkout_id');
            $table->enum('print_type', ['PRINT', 'REPRINT']);
            $table->unsignedBigInteger('printed_by');
            $table->timestamp('printed_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('setting_id', 'pos_receipt_print_logs_setting_idx');
            $table->index('pos_checkout_id', 'pos_receipt_print_logs_checkout_idx');
            
            $table->foreign('setting_id')->references('id')->on('settings')->onDelete('cascade');
            $table->foreign('pos_checkout_id')->references('id')->on('pos_checkouts')->onDelete('cascade');
            $table->foreign('printed_by')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_receipt_print_logs');
    }
};
