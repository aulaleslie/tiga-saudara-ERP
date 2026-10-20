<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pos_kas_bon_collections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pos_transaction_id');
            $table->unsignedBigInteger('setting_id');
            $table->unsignedBigInteger('pos_session_id');
            $table->unsignedBigInteger('pos_terminal_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('reference', 100)->unique();
            $table->dateTime('collected_at');
            $table->decimal('amount', 15, 2);
            $table->decimal('cash_tender_amount', 15, 2)->default(0);
            $table->decimal('change_amount', 15, 2)->default(0);
            $table->string('idempotency_key', 120)->unique();
            $table->string('request_hash', 64);
            $table->json('review_snapshot')->nullable();
            $table->json('receipt_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('pos_transaction_id', 'pkbc_trx_idx');
            $table->index('setting_id', 'pkbc_setting_idx');
            $table->index('pos_session_id', 'pkbc_session_idx');
            $table->index('pos_terminal_id', 'pkbc_terminal_idx');
            $table->index('user_id', 'pkbc_user_idx');
            $table->index('collected_at', 'pkbc_collected_at_idx');

            $table->foreign('pos_transaction_id', 'fk_pkbc_transaction')
                ->references('id')->on('pos_transactions')->onDelete('cascade');
            $table->foreign('setting_id', 'fk_pkbc_setting')
                ->references('id')->on('settings')->onDelete('restrict');
            $table->foreign('pos_session_id', 'fk_pkbc_session')
                ->references('id')->on('pos_sessions')->onDelete('restrict');
            $table->foreign('pos_terminal_id', 'fk_pkbc_terminal')
                ->references('id')->on('pos_terminals')->onDelete('set null');
            $table->foreign('user_id', 'fk_pkbc_user')
                ->references('id')->on('users')->onDelete('restrict');
        });

        Schema::create('pos_kas_bon_collection_methods', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pos_kas_bon_collection_id');
            $table->unsignedBigInteger('payment_method_id');
            $table->string('payment_method_name', 100);
            $table->decimal('amount', 15, 2);
            $table->decimal('tender_amount', 15, 2)->nullable();
            $table->decimal('change_amount', 15, 2)->default(0);
            $table->unsignedInteger('sequence_order')->default(0);
            $table->string('evidence_path', 255)->nullable();
            $table->json('evidence_metadata')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('pos_kas_bon_collection_id', 'pkbcm_collection_idx');
            $table->index('payment_method_id', 'pkbcm_method_idx');
            $table->index('sequence_order', 'pkbcm_seq_idx');

            $table->foreign('pos_kas_bon_collection_id', 'fk_pkbcm_collection')
                ->references('id')->on('pos_kas_bon_collections')->onDelete('cascade');
            $table->foreign('payment_method_id', 'fk_pkbcm_method')
                ->references('id')->on('payment_methods')->onDelete('restrict');
        });

        Schema::create('pos_kas_bon_collection_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pos_kas_bon_collection_id');
            $table->unsignedBigInteger('pos_kas_bon_collection_method_id');
            $table->unsignedBigInteger('pos_transaction_id');
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('sale_payment_id');
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->index('pos_kas_bon_collection_id', 'pkbca_coll_idx');
            $table->index('pos_kas_bon_collection_method_id', 'pkbca_meth_idx');
            $table->index('pos_transaction_id', 'pkbca_trx_idx');
            $table->index('sale_id', 'pkbca_sale_idx');
            $table->unique('sale_payment_id', 'pkbca_sale_payment_unique');

            $table->foreign('pos_kas_bon_collection_id', 'fk_pkbca_collection')
                ->references('id')->on('pos_kas_bon_collections')->onDelete('cascade');
            $table->foreign('pos_kas_bon_collection_method_id', 'fk_pkbca_method')
                ->references('id')->on('pos_kas_bon_collection_methods')->onDelete('cascade');
            $table->foreign('pos_transaction_id', 'fk_pkbca_transaction')
                ->references('id')->on('pos_transactions')->onDelete('cascade');
            $table->foreign('sale_id', 'fk_pkbca_sale')
                ->references('id')->on('sales')->onDelete('cascade');
            $table->foreign('sale_payment_id', 'fk_pkbca_sale_payment')
                ->references('id')->on('sale_payments')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_kas_bon_collection_allocations');
        Schema::dropIfExists('pos_kas_bon_collection_methods');
        Schema::dropIfExists('pos_kas_bon_collections');
    }
};
