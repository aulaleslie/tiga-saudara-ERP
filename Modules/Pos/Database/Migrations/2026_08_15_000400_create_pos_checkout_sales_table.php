<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_checkout_sales')) {
            return;
        }

        Schema::create('pos_checkout_sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pos_checkout_id');
            $table->string('split_key', 180);
            $table->unsignedBigInteger('source_setting_id');
            $table->unsignedBigInteger('source_location_id');
            $table->string('tax_bucket', 40);
            $table->unsignedBigInteger('sale_id')->nullable();
            $table->unsignedBigInteger('sale_payment_id')->nullable();
            $table->json('dispatch_ids')->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->decimal('paid_total', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['pos_checkout_id', 'split_key'], 'pos_checkout_sales_checkout_split_key_unique');
            $table->index('pos_checkout_id', 'pos_checkout_sales_checkout_idx');
            $table->index('sale_id', 'pos_checkout_sales_sale_idx');
            $table->index('sale_payment_id', 'pos_checkout_sales_sale_payment_idx');
            $table->index(['source_setting_id', 'source_location_id'], 'pos_checkout_sales_source_idx');

            $table->foreign('pos_checkout_id')->references('id')->on('pos_checkouts')->onDelete('cascade');
            $table->foreign('source_setting_id')->references('id')->on('settings')->onDelete('restrict');
            $table->foreign('source_location_id')->references('id')->on('locations')->onDelete('restrict');
            $table->foreign('sale_id')->references('id')->on('sales')->nullOnDelete();
            $table->foreign('sale_payment_id')->references('id')->on('sale_payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_checkout_sales');
    }
};
