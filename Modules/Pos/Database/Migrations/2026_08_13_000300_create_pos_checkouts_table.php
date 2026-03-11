<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_checkouts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pos_transaction_id')->nullable();
            $table->unsignedBigInteger('setting_id');
            $table->unsignedBigInteger('pos_session_id');
            $table->unsignedBigInteger('terminal_id');
            $table->unsignedBigInteger('cashier_user_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('status', 20)->default('FINALIZING');
            $table->string('idempotency_key', 100);
            $table->string('payload_hash', 64);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->decimal('paid_total', 15, 2)->default(0);
            $table->decimal('change_total', 15, 2)->default(0);
            $table->string('payment_method_code', 20);
            $table->string('payment_reference', 255)->nullable();
            $table->unsignedBigInteger('sale_id')->nullable();
            $table->unsignedBigInteger('sale_payment_id')->nullable();
            $table->json('dispatch_ids')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->text('failure_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(['setting_id', 'idempotency_key'], 'pos_checkouts_setting_idempotency_unique');
            $table->index('pos_transaction_id', 'pos_checkouts_transaction_idx');
            $table->index(['pos_session_id', 'status'], 'pos_checkouts_session_status_idx');
            $table->index('sale_id', 'pos_checkouts_sale_idx');
            $table->index('finalized_at', 'pos_checkouts_finalized_at_idx');

            $table->foreign('pos_transaction_id')->references('id')->on('pos_transactions')->nullOnDelete();
            $table->foreign('setting_id')->references('id')->on('settings')->onDelete('cascade');
            $table->foreign('pos_session_id')->references('id')->on('pos_sessions')->onDelete('cascade');
            $table->foreign('terminal_id')->references('id')->on('pos_terminals')->onDelete('restrict');
            $table->foreign('cashier_user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->foreign('sale_id')->references('id')->on('sales')->nullOnDelete();
            $table->foreign('sale_payment_id')->references('id')->on('sale_payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_checkouts');
    }
};
