<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('setting_id');
            $table->string('code', 60);
            $table->string('status', 20)->default('DRAFT');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('owner_user_id');
            $table->unsignedBigInteger('last_saved_by');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('source_pos_session_id');
            $table->unsignedBigInteger('completed_checkout_id')->nullable();
            $table->json('snapshot_totals')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['setting_id', 'code'], 'pos_transactions_setting_code_unique');
            $table->index(['setting_id', 'status'], 'pos_transactions_setting_status_idx');
            $table->index(['owner_user_id', 'status'], 'pos_transactions_owner_status_idx');
            $table->index('source_pos_session_id', 'pos_transactions_session_idx');

            $table->foreign('setting_id')->references('id')->on('settings')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('owner_user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('last_saved_by')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->foreign('source_pos_session_id')->references('id')->on('pos_sessions')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_transactions');
    }
};
