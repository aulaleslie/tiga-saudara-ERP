<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_draft_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_draft_id')->constrained('pos_drafts')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('product_name')->nullable();
            $table->integer('quantity')->default(0);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('sub_total', 15, 2)->default(0);
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['pos_draft_id', 'product_id'], 'pos_draft_items_draft_product_idx');
        });

        Schema::create('pos_submit_idempotencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('setting_id')->constrained('settings')->cascadeOnDelete();
            $table->foreignId('pos_draft_id')->constrained('pos_drafts')->cascadeOnDelete();
            $table->string('idempotency_key', 100);
            $table->foreignId('pos_receipt_id')->nullable()->constrained('pos_receipts')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('response_payload')->nullable();
            $table->timestamps();

            $table->unique(['setting_id', 'pos_draft_id', 'idempotency_key'], 'pos_submit_idempotency_unique');
            $table->index(['setting_id', 'created_at'], 'pos_submit_idempotency_setting_created_idx');
        });

        Schema::create('pos_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('setting_id')->constrained('settings')->cascadeOnDelete();
            $table->foreignId('pos_draft_id')->nullable()->constrained('pos_drafts')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pos_code')->nullable();
            $table->string('action', 80);
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['setting_id', 'action', 'created_at'], 'pos_audit_logs_setting_action_created_idx');
            $table->index(['pos_code', 'created_at'], 'pos_audit_logs_code_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_audit_logs');
        Schema::dropIfExists('pos_submit_idempotencies');
        Schema::dropIfExists('pos_draft_items');
    }
};
