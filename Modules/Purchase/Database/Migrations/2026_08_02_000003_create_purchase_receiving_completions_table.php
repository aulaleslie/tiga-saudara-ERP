<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_receiving_completions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('setting_id');
            $table->unsignedBigInteger('purchase_id');
            $table->unsignedBigInteger('actor_user_id');
            $table->text('reason');
            $table->json('source_snapshot');
            $table->json('final_snapshot');
            $table->json('financial_before_after');
            $table->timestamps();

            $table->foreign('setting_id')->references('id')->on('settings')->onDelete('restrict');
            $table->foreign('purchase_id')->references('id')->on('purchases')->onDelete('restrict');
            $table->foreign('actor_user_id')->references('id')->on('users')->onDelete('restrict');

            $table->index('setting_id');
            $table->index('purchase_id');
            $table->index('actor_user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_receiving_completions');
    }
};
