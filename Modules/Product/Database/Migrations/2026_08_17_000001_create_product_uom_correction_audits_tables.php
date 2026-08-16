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
        Schema::create('product_uom_correction_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('old_unit_id');
            $table->unsignedBigInteger('new_unit_id');
            $table->decimal('conversion_factor', 12, 6);
            $table->json('quantities_before');
            $table->json('quantities_after');
            $table->json('cost_basis_before')->nullable();
            $table->json('cost_basis_after')->nullable();
            $table->json('purchase_details_before')->nullable();
            $table->json('purchase_details_after')->nullable();
            $table->text('reason');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->text('rounding_notes')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('product_id')->references('id')->on('products')->onDelete('restrict');
            $table->foreign('old_unit_id')->references('id')->on('units')->onDelete('restrict');
            $table->foreign('new_unit_id')->references('id')->on('units')->onDelete('restrict');
            $table->foreign('actor_user_id')->references('id')->on('users')->onDelete('set null');

            $table->index('product_id');
            $table->index('actor_user_id');
            $table->index('created_at');
        });

        Schema::create('product_uom_correction_removed_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('audit_id');
            $table->string('document_type'); // 'POS' or 'SALE'
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('reference');
            $table->string('status');
            $table->decimal('payment_amount', 14, 2)->default(0);
            $table->string('owner_or_customer')->nullable();
            $table->timestamp('document_created_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('audit_id')->references('id')->on('product_uom_correction_audits')->onDelete('cascade');
            $table->index('audit_id');
            $table->index('document_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_uom_correction_removed_documents');
        Schema::dropIfExists('product_uom_correction_audits');
    }
};
