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
        Schema::create('consignment_sold_sources', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('setting_id');
            $table->unsignedBigInteger('dispatch_detail_id');
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('pos_checkout_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');
            $table->decimal('original_base_quantity', 12, 3);
            $table->timestamp('dispatched_at')->nullable();
            $table->json('tax_context')->nullable();
            $table->json('serial_identities')->nullable();
            $table->string('source_hash', 64);
            $table->json('source_snapshot');
            $table->text('reconstruction_notes')->nullable();
            $table->boolean('has_reconstruction_blocker')->default(false);
            $table->text('blocker_reason')->nullable();
            $table->timestamps();

            $table->foreign('setting_id', 'fk_css_setting')->references('id')->on('settings')->onDelete('restrict');
            $table->foreign('dispatch_detail_id', 'fk_css_dispatch_detail')->references('id')->on('dispatch_details')->onDelete('restrict');
            $table->foreign('sale_id', 'fk_css_sale')->references('id')->on('sales')->onDelete('restrict');
            $table->foreign('product_id', 'fk_css_product')->references('id')->on('products')->onDelete('restrict');
            $table->foreign('location_id', 'fk_css_location')->references('id')->on('locations')->onDelete('restrict');

            if (Schema::hasTable('pos_checkouts')) {
                $table->foreign('pos_checkout_id', 'fk_css_pos_checkout')->references('id')->on('pos_checkouts')->nullOnDelete();
            }

            $table->unique(['dispatch_detail_id'], 'uniq_css_dispatch_detail');
            $table->index(['setting_id', 'location_id'], 'idx_css_setting_location');
            $table->index(['setting_id', 'product_id'], 'idx_css_setting_product');
            $table->index(['sale_id'], 'idx_css_sale');
            $table->index(['pos_checkout_id'], 'idx_css_pos_checkout');
        });

        Schema::create('consignment_sold_source_serials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consignment_sold_source_id');
            $table->unsignedBigInteger('product_serial_number_id');
            $table->timestamps();

            $table->foreign('consignment_sold_source_id', 'fk_csss_source')->references('id')->on('consignment_sold_sources')->onDelete('cascade');
            $table->foreign('product_serial_number_id', 'fk_csss_serial')->references('id')->on('product_serial_numbers')->onDelete('restrict');

            $table->unique(['consignment_sold_source_id', 'product_serial_number_id'], 'uniq_csss_source_serial');
            $table->index(['product_serial_number_id'], 'idx_csss_serial');
        });

        Schema::create('consignment_billing_confirmations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('setting_id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('confirmation_number', 100);
            $table->string('status', 50)->default('DRAFT'); // DRAFT, WAITING_APPROVAL, APPROVED, REJECTED
            $table->date('date');
            $table->text('notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('source_hash', 64)->nullable();
            $table->unsignedBigInteger('purchase_id')->nullable();
            $table->boolean('is_ready_for_billing')->default(false);
            $table->timestamps();

            $table->foreign('setting_id', 'fk_cbc_setting')->references('id')->on('settings')->onDelete('restrict');
            $table->foreign('supplier_id', 'fk_cbc_supplier')->references('id')->on('suppliers')->onDelete('restrict');
            $table->foreign('created_by', 'fk_cbc_created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('submitted_by', 'fk_cbc_submitted_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by', 'fk_cbc_approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('rejected_by', 'fk_cbc_rejected_by')->references('id')->on('users')->nullOnDelete();

            if (Schema::hasTable('purchases')) {
                $table->foreign('purchase_id', 'fk_cbc_purchase')->references('id')->on('purchases')->nullOnDelete();
            }

            $table->unique(['setting_id', 'confirmation_number'], 'uniq_cbc_setting_num');
            $table->index(['setting_id', 'supplier_id', 'status'], 'idx_cbc_setting_supp_status');
            $table->index(['status'], 'idx_cbc_status');
        });

        Schema::create('consignment_billing_confirmation_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consignment_billing_confirmation_id');
            $table->unsignedBigInteger('consignment_sold_source_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');
            $table->decimal('allocated_base_quantity', 12, 3);
            $table->json('sold_source_snapshot')->nullable();
            $table->timestamps();

            $table->foreign('consignment_billing_confirmation_id', 'fk_cbcl_confirmation')->references('id')->on('consignment_billing_confirmations')->onDelete('cascade');
            $table->foreign('consignment_sold_source_id', 'fk_cbcl_sold_source')->references('id')->on('consignment_sold_sources')->onDelete('restrict');
            $table->foreign('product_id', 'fk_cbcl_product')->references('id')->on('products')->onDelete('restrict');
            $table->foreign('location_id', 'fk_cbcl_location')->references('id')->on('locations')->onDelete('restrict');

            $table->unique(['consignment_billing_confirmation_id', 'consignment_sold_source_id'], 'uniq_cbcl_conf_source');
            $table->index(['consignment_billing_confirmation_id', 'consignment_sold_source_id'], 'idx_cbcl_conf_source');
            $table->index(['consignment_sold_source_id'], 'idx_cbcl_sold_source');
        });

        Schema::create('consignment_receipt_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consignment_billing_confirmation_line_id');
            $table->unsignedBigInteger('consignment_receiving_detail_id');
            $table->decimal('allocated_base_quantity', 12, 3);
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('unit_dpp', 15, 2);
            $table->unsignedBigInteger('tax_id')->nullable();
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->string('receival_reference', 100)->nullable();
            $table->string('receiving_reference', 100)->nullable();
            $table->json('receiving_detail_snapshot')->nullable();
            $table->timestamps();

            $table->foreign('consignment_billing_confirmation_line_id', 'fk_cra_line')->references('id')->on('consignment_billing_confirmation_lines')->onDelete('cascade');
            $table->foreign('consignment_receiving_detail_id', 'fk_cra_crd')->references('id')->on('consignment_receiving_details')->onDelete('restrict');
            $table->foreign('tax_id', 'fk_cra_tax')->references('id')->on('taxes')->nullOnDelete();

            $table->unique(['consignment_billing_confirmation_line_id', 'consignment_receiving_detail_id'], 'uniq_cra_line_crd');
            $table->index(['consignment_billing_confirmation_line_id'], 'idx_cra_line_id');
            $table->index(['consignment_receiving_detail_id'], 'idx_cra_crd_id');
        });

        Schema::create('consignment_serialized_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consignment_billing_confirmation_id');
            $table->unsignedBigInteger('consignment_billing_confirmation_line_id');
            $table->unsignedBigInteger('consignment_sold_source_id');
            $table->unsignedBigInteger('product_serial_number_id');
            $table->unsignedBigInteger('consignment_receiving_detail_id');
            $table->string('status', 50)->default('RESERVED'); // RESERVED, APPROVED, RELEASED
            $table->timestamps();

            $table->foreign('consignment_billing_confirmation_id', 'fk_csa_conf')->references('id')->on('consignment_billing_confirmations')->onDelete('cascade');
            $table->foreign('consignment_billing_confirmation_line_id', 'fk_csa_line')->references('id')->on('consignment_billing_confirmation_lines')->onDelete('cascade');
            $table->foreign('consignment_sold_source_id', 'fk_csa_sold_source')->references('id')->on('consignment_sold_sources')->onDelete('restrict');
            $table->foreign('product_serial_number_id', 'fk_csa_serial')->references('id')->on('product_serial_numbers')->onDelete('restrict');
            $table->foreign('consignment_receiving_detail_id', 'fk_csa_crd')->references('id')->on('consignment_receiving_details')->onDelete('restrict');

            $table->index(['consignment_billing_confirmation_line_id'], 'idx_csa_line');
            $table->index(['product_serial_number_id'], 'idx_csa_serial');
        });

        Schema::create('consignment_active_serial_claims', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_serial_number_id');
            $table->unsignedBigInteger('consignment_billing_confirmation_id');
            $table->unsignedBigInteger('consignment_serialized_allocation_id');
            $table->timestamps();

            $table->foreign('product_serial_number_id', 'fk_casc_serial')->references('id')->on('product_serial_numbers')->onDelete('restrict');
            $table->foreign('consignment_billing_confirmation_id', 'fk_casc_conf')->references('id')->on('consignment_billing_confirmations')->onDelete('cascade');
            $table->foreign('consignment_serialized_allocation_id', 'fk_casc_csa')->references('id')->on('consignment_serialized_allocations')->onDelete('cascade');

            $table->unique(['product_serial_number_id'], 'uniq_casc_serial');
        });

        Schema::create('consignment_allocation_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consignment_billing_confirmation_id');
            $table->string('action', 50);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('reason')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();

            $table->foreign('consignment_billing_confirmation_id', 'fk_caal_conf')->references('id')->on('consignment_billing_confirmations')->onDelete('cascade');
            $table->foreign('actor_id', 'fk_caal_actor')->references('id')->on('users')->nullOnDelete();

            $table->index(['consignment_billing_confirmation_id'], 'idx_caal_conf_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consignment_allocation_audit_logs');
        Schema::dropIfExists('consignment_active_serial_claims');
        Schema::dropIfExists('consignment_serialized_allocations');
        Schema::dropIfExists('consignment_receipt_allocations');
        Schema::dropIfExists('consignment_billing_confirmation_lines');
        Schema::dropIfExists('consignment_billing_confirmations');
        Schema::dropIfExists('consignment_sold_source_serials');
        Schema::dropIfExists('consignment_sold_sources');
    }
};
