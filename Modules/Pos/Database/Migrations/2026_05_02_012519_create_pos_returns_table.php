<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('pos_returns', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->unsignedBigInteger('setting_id')->index();
            $table->unsignedBigInteger('pos_transaction_id')->index();
            $table->unsignedBigInteger('pos_checkout_id')->index();
            $table->string('transaction_code')->index();
            $table->string('receipt_number')->index();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->string('customer_name')->nullable();
            $table->string('return_option'); // cash_return | product_replacement
            $table->string('status')->index(); // draft | pending_approval | approved | rejected | awaiting_receiving | awaiting_settlement | awaiting_dispatch | completed | archived | cancelled
            $table->string('approval_status')->index(); // draft | pending | approved | rejected
            $table->boolean('is_reversed')->default(false)->index();
            $table->json('source_snapshot');
            $table->string('source_snapshot_hash')->index();
            $table->decimal('total_amount', 16, 2);
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable()->index();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('received_by')->nullable()->index();
            $table->timestamp('received_at')->nullable();
            $table->unsignedBigInteger('settled_by')->nullable()->index();
            $table->timestamp('settled_at')->nullable();
            $table->unsignedBigInteger('archived_by')->nullable()->index();
            $table->timestamp('archived_at')->nullable();
            $table->text('archive_reason')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable()->index();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('pos_returns');
    }
};
