<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_session_cash_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('setting_id');
            $table->unsignedBigInteger('pos_session_id');
            $table->string('event_type', 50);
            $table->string('direction', 10);
            $table->decimal('amount', 15, 2);
            $table->json('denominations')->nullable();
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->unsignedBigInteger('performed_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['pos_session_id', 'occurred_at']);
            $table->index(['setting_id', 'event_type', 'occurred_at']);
            $table->index(['reference_type', 'reference_id']);

            $table->foreign('setting_id')->references('id')->on('settings')->onDelete('cascade');
            $table->foreign('pos_session_id')->references('id')->on('pos_sessions')->onDelete('cascade');
            $table->foreign('performed_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_session_cash_events');
    }
};
