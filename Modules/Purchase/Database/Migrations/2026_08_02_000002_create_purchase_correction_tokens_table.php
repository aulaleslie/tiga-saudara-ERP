<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_correction_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique()->index();
            $table->foreignId('purchase_id')->constrained('purchases')->onDelete('cascade');
            $table->foreignId('setting_id')->constrained('settings')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('session_id');
            $table->foreignId('selected_payment_id')->nullable()->constrained('purchase_payments')->onDelete('set null');
            $table->string('correction_payload_hash');
            $table->decimal('source_document_total', 15, 2);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['purchase_id', 'setting_id']);
            $table->index(['user_id', 'expires_at']);
            $table->index('consumed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_correction_tokens');
    }
};
