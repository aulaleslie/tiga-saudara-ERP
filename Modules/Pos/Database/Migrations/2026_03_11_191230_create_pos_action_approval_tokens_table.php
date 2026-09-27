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
        Schema::create('pos_action_approval_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_request_id')->unique()->constrained('pos_action_approval_requests')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->foreignId('consumed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('consumed_context')->nullable();
            $table->timestamps();

            $table->index(['expires_at', 'consumed_at'], 'pos_approval_tokens_expires_consumed_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_action_approval_tokens');
    }
};
