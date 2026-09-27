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
        Schema::create('transfer_action_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('action'); // e.g., 'CREATED', 'APPROVED', 'REJECTED'
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->timestamps();

            $table->index(['transfer_id', 'revision']);
            // If idempotency_key is provided, a specific action should only be processed once per key
            $table->unique(['idempotency_key', 'action']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfer_action_histories');
    }
};
