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
        Schema::create('journal_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('journal_id');
            $table->unsignedBigInteger('chart_of_account_id');
            $table->decimal('amount', 15, 2);
            // Specify whether this line is a debit or credit
            $table->enum('type', ['debit', 'credit']);
            $table->timestamps();

            $table->foreign('journal_id')
                ->references('id')->on('journals')
                ->onDelete('cascade');

            $table->foreign('chart_of_account_id')
                ->references('id')->on('chart_of_accounts')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_items');
    }
};
