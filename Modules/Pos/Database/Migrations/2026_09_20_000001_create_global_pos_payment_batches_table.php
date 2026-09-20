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
        Schema::create('global_pos_payment_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('user_id'); // actor
            $table->date('date');
            $table->string('reference', 255);
            $table->unsignedBigInteger('payment_method_id');
            $table->text('note')->nullable();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->timestamps();

            $table->index('customer_id', 'gppb_customer_id_idx');
            $table->index('user_id', 'gppb_user_id_idx');
            $table->index('payment_method_id', 'gppb_payment_method_id_idx');
            $table->index('date', 'gppb_date_idx');
            $table->index('reference', 'gppb_reference_idx');

            $table->foreign('customer_id', 'gppb_customer_fk')
                ->references('id')->on('customers')->onDelete('restrict');
            $table->foreign('user_id', 'gppb_user_fk')
                ->references('id')->on('users')->onDelete('restrict');
            $table->foreign('payment_method_id', 'gppb_payment_method_fk')
                ->references('id')->on('payment_methods')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('global_pos_payment_batches');
    }
};
