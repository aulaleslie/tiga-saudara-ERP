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
        Schema::create('consignment_receivals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('setting_id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('reference', 100);
            $table->string('supplier_delivery_reference', 100)->nullable();
            $table->date('date');
            $table->string('status', 50)->default('DRAFT'); // DRAFT, WAITING_APPROVAL, APPROVED, REJECTED
            $table->text('note')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('setting_id')->references('id')->on('settings')->onDelete('restrict');
            $table->foreign('supplier_id')->references('id')->on('suppliers')->onDelete('restrict');
            $table->foreign('submitted_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('rejected_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['setting_id', 'reference'], 'idx_consignment_receivals_setting_ref_uniq');
            $table->index(['setting_id', 'status'], 'idx_consignment_receivals_setting_status');
            $table->index(['supplier_id'], 'idx_consignment_receivals_supplier');
        });

        Schema::create('consignment_receival_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consignment_receival_id')
                ->constrained('consignment_receivals')
                ->onDelete('cascade');
            $table->unsignedBigInteger('product_id');
            $table->string('product_name', 255);
            $table->string('product_code', 100);
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->string('unit_code', 50)->nullable();
            $table->unsignedBigInteger('tax_id')->nullable();
            $table->string('tax_name', 100)->nullable();
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('unit_dpp', 15, 2);
            $table->decimal('subtotal_cost', 15, 2);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_cost', 15, 2);
            $table->boolean('is_serialized')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('restrict');
            $table->foreign('unit_id')->references('id')->on('units')->nullOnDelete();
            $table->foreign('tax_id')->references('id')->on('taxes')->nullOnDelete();

            $table->index(['consignment_receival_id', 'product_id'], 'idx_crl_receival_product');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consignment_receival_lines');
        Schema::dropIfExists('consignment_receivals');
    }
};
