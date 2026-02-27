<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_supervisor_approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('setting_id');
            $table->string('action_type', 50);
            $table->string('target_type', 50);
            $table->unsignedBigInteger('target_id');
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('approval_result', 20);
            $table->text('reason')->nullable();
            $table->json('context_snapshot')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['setting_id', 'action_type', 'occurred_at']);
            $table->index(['target_type', 'target_id']);
            $table->index(['approved_by', 'occurred_at']);

            $table->foreign('setting_id')->references('id')->on('settings')->onDelete('cascade');
            $table->foreign('requested_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_supervisor_approvals');
    }
};
