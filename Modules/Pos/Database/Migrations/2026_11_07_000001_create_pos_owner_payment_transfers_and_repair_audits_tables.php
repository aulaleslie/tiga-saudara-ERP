<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('pos_owner_payment_transfers')) {
            Schema::create('pos_owner_payment_transfers', function (Blueprint $table) {
                $table->id();
                $table->string('execution_key', 191)->unique('popt_execution_key_unique');
                $table->unsignedBigInteger('pos_transaction_id')->nullable()->index('popt_pos_trx_idx');
                $table->unsignedBigInteger('pos_checkout_id')->nullable()->index('popt_pos_checkout_idx');
                $table->unsignedBigInteger('pos_return_id')->index('popt_pos_return_idx');
                $table->unsignedBigInteger('pos_return_line_id')->nullable()->index('popt_pos_return_line_idx');
                $table->unsignedBigInteger('sale_return_id')->nullable()->index('popt_sale_return_idx');
                $table->unsignedBigInteger('sale_return_detail_id')->index('popt_sale_return_detail_idx');
                $table->unsignedBigInteger('source_sale_id')->index('popt_source_sale_idx');
                $table->unsignedBigInteger('replacement_sale_id')->index('popt_replacement_sale_idx');
                $table->unsignedBigInteger('original_sale_payment_id')->index('popt_original_payment_idx');
                $table->unsignedBigInteger('predecessor_transfer_id')->nullable()->index('popt_predecessor_transfer_idx');
                $table->unsignedBigInteger('invalidated_synthetic_payment_id')->nullable()->index('popt_invalidated_payment_idx');
                $table->unsignedBigInteger('payment_method_id')->nullable()->index('popt_payment_method_idx');
                $table->string('payment_method', 100);
                $table->decimal('amount', 15, 2);
                $table->bigInteger('amount_minor');
                $table->string('transfer_source', 64)->default('LIVE_EXECUTION');
                $table->unsignedBigInteger('actor_id')->nullable()->index('popt_actor_idx');
                $table->timestamp('executed_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('pos_replacement_accounting_repair_audits')) {
            Schema::create('pos_replacement_accounting_repair_audits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('setting_id')->index('prara_setting_idx');
                $table->unsignedBigInteger('pos_transaction_id')->index('prara_pos_trx_idx');
                $table->unsignedBigInteger('pos_checkout_id')->nullable()->index('prara_pos_checkout_idx');
                $table->unsignedBigInteger('pos_return_id')->nullable()->index('prara_pos_return_idx');
                $table->unsignedBigInteger('actor_id')->nullable()->index('prara_actor_idx');
                $table->text('reason');
                $table->string('status', 32)->default('APPLIED');
                $table->string('evidence_fingerprint', 64)->index('prara_fingerprint_idx');
                $table->json('source_sale_ids')->nullable();
                $table->json('replacement_sale_ids')->nullable();
                $table->json('original_payment_ids')->nullable();
                $table->json('invalidated_payment_ids')->nullable();
                $table->json('transfer_ids')->nullable();
                $table->json('before_values');
                $table->json('after_values');
                $table->timestamps();

                $table->unique(['pos_transaction_id', 'evidence_fingerprint'], 'prara_trx_fingerprint_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['pos_owner_payment_transfers', 'pos_replacement_accounting_repair_audits'] as $ledger) {
            if (Schema::hasTable($ledger) && DB::table($ledger)->exists()) {
                throw new \RuntimeException("Rollback refused: {$ledger} already holds financial/audit evidence. Fix forward instead.");
            }
        }

        Schema::dropIfExists('pos_replacement_accounting_repair_audits');
        Schema::dropIfExists('pos_owner_payment_transfers');
    }
};
