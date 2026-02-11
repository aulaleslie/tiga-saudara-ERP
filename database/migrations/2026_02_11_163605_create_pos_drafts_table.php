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
        Schema::create('pos_drafts', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('pos_session_id')->nullable()->constrained('pos_sessions')->nullOnDelete();
            $table->foreignId('setting_id')->constrained('settings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            
            // Allow storing the final receipt ID if draft completes
            $table->foreignId('pos_receipt_id')->nullable()->constrained('pos_receipts')->nullOnDelete();
            
            $table->string('status')->index(); // OPEN, LOCKED, VOID, EXPIRED, COMPLETED
            $table->timestamp('expires_at')->nullable()->index();
            
            // Locking mechanism
            $table->foreignId('locked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            
            $table->json('payload')->nullable(); // Stores cart, customer selection, etc.
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_drafts');
    }
};
