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
        Schema::create('pos_action_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('setting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_session_id')->constrained('pos_sessions')->cascadeOnDelete();
            $table->string('action_type', 50);
            $table->string('target_type', 100);
            $table->unsignedBigInteger('target_id');
            $table->json('request_payload')->nullable();
            
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('PENDING'); // PENDING, APPROVED, REJECTED, CANCELLED, EXPIRED, CONSUMED
            
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            
            $table->timestamps();

            $table->index(['setting_id', 'status', 'created_at'], 'pos_approval_reqs_setting_status_created_idx');
            $table->index(['requested_by', 'status'], 'pos_approval_reqs_requested_by_status_idx');
            $table->index(['pos_session_id', 'status'], 'pos_approval_reqs_session_status_idx');
            $table->index(['action_type', 'target_type', 'target_id'], 'pos_approval_reqs_action_target_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_action_approval_requests');
    }
};
