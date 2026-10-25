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
        if (Schema::hasTable('pos_return_balance_repair_audits')) {
            return;
        }

        Schema::create('pos_return_balance_repair_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pos_transaction_id')->index();
            $table->unsignedBigInteger('pos_checkout_id')->nullable()->index();
            $table->unsignedBigInteger('sale_id')->nullable()->index();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->text('reason');
            $table->string('evidence_fingerprint', 64)->index();
            $table->json('before_values');
            $table->json('after_values');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_return_balance_repair_audits');
    }
};
