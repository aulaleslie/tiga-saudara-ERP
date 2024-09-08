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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->foreignId('setting_id')->constrained('settings')->onDelete('cascade');
            $table->enum('type', ['ADJ', 'SELL', 'BUY', 'TRF'])->comment('Type of transaction');
            $table->integer('quantity')->comment('Quantity involved in the transaction');
            $table->integer('current_quantity')->comment('Product quantity after the transaction');
            $table->integer('broken_quantity')->nullable()->comment('Broken quantity if applicable');
            $table->foreignId('location_id')->constrained('locations')->onDelete('cascade');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->text('reason')->nullable()->comment('Reason for the transaction');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
