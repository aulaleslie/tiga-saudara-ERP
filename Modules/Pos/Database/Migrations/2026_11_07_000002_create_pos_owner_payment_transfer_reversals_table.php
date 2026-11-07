<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_owner_payment_transfer_reversals')) {
            return;
        }

        Schema::create('pos_owner_payment_transfer_reversals', function (Blueprint $table) {
            $table->id();
            // Intentionally not a foreign key: the transfer row may be fully removed, the snapshot preserves evidence.
            $table->unsignedBigInteger('transfer_id')->index('poptr_transfer_idx');
            $table->unsignedBigInteger('sale_return_id')->nullable()->index('poptr_sale_return_idx');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->bigInteger('reduced_minor');
            $table->bigInteger('amount_minor_before');
            $table->bigInteger('amount_minor_after');
            $table->boolean('transfer_deleted')->default(false);
            $table->string('reason', 191)->nullable();
            $table->json('transfer_snapshot');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('pos_owner_payment_transfer_reversals') && DB::table('pos_owner_payment_transfer_reversals')->exists()) {
            throw new \RuntimeException('Rollback refused: pos_owner_payment_transfer_reversals already holds reversal evidence. Fix forward instead.');
        }

        Schema::dropIfExists('pos_owner_payment_transfer_reversals');
    }
};
