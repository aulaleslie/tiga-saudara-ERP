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
        // 1. Add source classification to purchases table
        if (Schema::hasTable('purchases') && !Schema::hasColumn('purchases', 'source_type')) {
            Schema::table('purchases', function (Blueprint $table) {
                $table->string('source_type', 50)->default('ORDINARY')->after('status');
                $table->index(['source_type'], 'idx_purchases_source_type');
            });
        }

        // 2. Add confirmation billing metadata columns to consignment_billing_confirmations table
        if (Schema::hasTable('consignment_billing_confirmations')) {
            Schema::table('consignment_billing_confirmations', function (Blueprint $table) {
                if (!Schema::hasColumn('consignment_billing_confirmations', 'billed_by')) {
                    $table->unsignedBigInteger('billed_by')->nullable()->after('approved_at');
                    $table->foreign('billed_by', 'fk_cbc_billed_by')->references('id')->on('users')->nullOnDelete();
                }
                if (!Schema::hasColumn('consignment_billing_confirmations', 'billed_at')) {
                    $table->timestamp('billed_at')->nullable()->after('billed_by');
                }
                if (!Schema::hasColumn('consignment_billing_confirmations', 'supplier_invoice_number')) {
                    $table->string('supplier_invoice_number', 100)->nullable()->after('billed_at');
                }
                if (!Schema::hasColumn('consignment_billing_confirmations', 'invoice_date')) {
                    $table->date('invoice_date')->nullable()->after('supplier_invoice_number');
                }
                if (!Schema::hasColumn('consignment_billing_confirmations', 'reporting_date')) {
                    $table->date('reporting_date')->nullable()->after('invoice_date');
                }
                if (!Schema::hasColumn('consignment_billing_confirmations', 'due_date')) {
                    $table->date('due_date')->nullable()->after('reporting_date');
                }
                if (!Schema::hasColumn('consignment_billing_confirmations', 'payment_term_id')) {
                    $table->unsignedBigInteger('payment_term_id')->nullable()->after('due_date');
                    $table->foreign('payment_term_id', 'fk_cbc_payment_term')->references('id')->on('payment_terms')->nullOnDelete();
                }
                if (!Schema::hasColumn('consignment_billing_confirmations', 'tax_ref_no')) {
                    $table->string('tax_ref_no', 100)->nullable()->after('payment_term_id');
                }
                if (!Schema::hasColumn('consignment_billing_confirmations', 'billing_notes')) {
                    $table->text('billing_notes')->nullable()->after('tax_ref_no');
                }
            });
        }

        // 3. Ensure unique constraint on purchase_id for consignment_billing_confirmations
        if (Schema::hasTable('consignment_billing_confirmations')) {
            Schema::table('consignment_billing_confirmations', function (Blueprint $table) {
                $table->unique(['purchase_id'], 'uniq_cbc_purchase_id');
            });
        }

        // 3b. Add explicit tax snapshot version classification to receipt allocations.
        // Existing rows predate proportional tax persistence, so they are migrated to
        // version 1 (legacy full-lot tax evidence). New rows are written as version 2.
        if (Schema::hasTable('consignment_receipt_allocations') && !Schema::hasColumn('consignment_receipt_allocations', 'tax_snapshot_version')) {
            Schema::table('consignment_receipt_allocations', function (Blueprint $table) {
                $table->unsignedSmallInteger('tax_snapshot_version')->nullable()->after('tax_amount');
                $table->index(['tax_snapshot_version'], 'idx_cra_tax_snapshot_version');
            });

            // Cutover: classify every pre-existing allocation explicitly as legacy v1.
            DB::table('consignment_receipt_allocations')
                ->whereNull('tax_snapshot_version')
                ->update(['tax_snapshot_version' => 1]);
        }

        // 4. Create lineage table linking purchase_details to consignment receipt / serialized allocations
        if (!Schema::hasTable('consignment_purchase_detail_lineages')) {
            Schema::create('consignment_purchase_detail_lineages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('setting_id');
                $table->unsignedBigInteger('purchase_id');
                $table->unsignedBigInteger('purchase_detail_id');
                $table->unsignedBigInteger('consignment_billing_confirmation_id');
                $table->unsignedBigInteger('consignment_billing_confirmation_line_id');
                $table->unsignedBigInteger('consignment_receipt_allocation_id')->nullable();
                $table->unsignedBigInteger('consignment_serialized_allocation_id')->nullable();
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('consignment_receiving_detail_id');
                $table->decimal('billed_base_quantity', 12, 3);
                $table->decimal('unit_cost', 15, 2);
                $table->decimal('unit_dpp', 15, 2);
                $table->unsignedBigInteger('tax_id')->nullable();
                $table->decimal('tax_rate', 8, 4)->default(0);
                $table->decimal('tax_amount', 15, 2)->default(0);
                $table->json('commercial_snapshot')->nullable();
                $table->timestamps();

                $table->foreign('setting_id', 'fk_cpdl_setting')->references('id')->on('settings')->onDelete('restrict');
                $table->foreign('purchase_id', 'fk_cpdl_purchase')->references('id')->on('purchases')->onDelete('restrict');
                $table->foreign('purchase_detail_id', 'fk_cpdl_detail')->references('id')->on('purchase_details')->onDelete('restrict');
                $table->foreign('consignment_billing_confirmation_id', 'fk_cpdl_conf')->references('id')->on('consignment_billing_confirmations')->onDelete('restrict');
                $table->foreign('consignment_billing_confirmation_line_id', 'fk_cpdl_conf_line')->references('id')->on('consignment_billing_confirmation_lines')->onDelete('restrict');
                $table->foreign('consignment_receipt_allocation_id', 'fk_cpdl_cra')->references('id')->on('consignment_receipt_allocations')->onDelete('restrict');
                $table->foreign('consignment_serialized_allocation_id', 'fk_cpdl_csa')->references('id')->on('consignment_serialized_allocations')->onDelete('restrict');
                $table->foreign('product_id', 'fk_cpdl_product')->references('id')->on('products')->onDelete('restrict');
                $table->foreign('consignment_receiving_detail_id', 'fk_cpdl_crd')->references('id')->on('consignment_receiving_details')->onDelete('restrict');
                $table->foreign('tax_id', 'fk_cpdl_tax')->references('id')->on('taxes')->nullOnDelete();

                $table->unique(['consignment_serialized_allocation_id'], 'uniq_cpdl_csa');
                $table->index(['consignment_receipt_allocation_id'], 'idx_cpdl_cra');
                $table->index(['purchase_id'], 'idx_cpdl_purchase');
                $table->index(['purchase_detail_id'], 'idx_cpdl_detail');
                $table->index(['consignment_billing_confirmation_id'], 'idx_cpdl_conf');
            });
        }

        // 5. Migrate purchase_details.quantity column to DECIMAL(15, 3) to support fractional consignment quantities
        if (Schema::hasTable('purchase_details') && Schema::hasColumn('purchase_details', 'quantity')) {
            Schema::table('purchase_details', function (Blueprint $table) {
                $table->decimal('quantity', 15, 3)->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Guard BEFORE any destructive step: refusing rollback if any Phase 3 consignment billing records exist
        $hasLineage = Schema::hasTable('consignment_purchase_detail_lineages')
            && DB::table('consignment_purchase_detail_lineages')->exists();

        $hasBilledConfirmation = Schema::hasTable('consignment_billing_confirmations')
            && (
                Schema::hasColumn('consignment_billing_confirmations', 'billed_at')
                    ? DB::table('consignment_billing_confirmations')->whereNotNull('billed_at')->exists()
                    : false
            );

        $hasConsignmentPurchase = Schema::hasTable('purchases')
            && Schema::hasColumn('purchases', 'source_type')
            && DB::table('purchases')->where('source_type', 'CONSIGNMENT_BILLING')->exists();

        if ($hasLineage || $hasBilledConfirmation || $hasConsignmentPurchase) {
            throw new RuntimeException(
                'Cannot roll back: Phase 3 consignment billing records exist. '
                . 'Rollback would destroy financial provenance. '
                . 'Reconcile or remove those billing records before rolling back this migration.'
            );
        }
        // Guard BEFORE any destructive step: version 2 allocations store proportional tax.
        // Dropping tax_snapshot_version would leave those amounts intact but strip the marker
        // that explains them, and a subsequent roll-forward would backfill them as legacy v1
        // full-lot tax — silently corrupting tax reconciliation. This applies even with no
        // Phase 3 Purchases, since Phase 2 may already have created v2 allocations.
        if (Schema::hasTable('consignment_receipt_allocations') && Schema::hasColumn('consignment_receipt_allocations', 'tax_snapshot_version')) {
            $proportionalCount = DB::table('consignment_receipt_allocations')
                ->where('tax_snapshot_version', 2)
                ->count();

            if ($proportionalCount > 0) {
                throw new RuntimeException(
                    "Cannot roll back: {$proportionalCount} consignment receipt allocation(s) carry proportional (v2) tax semantics. "
                    . 'Dropping tax_snapshot_version would reclassify them as legacy v1 on the next roll-forward. '
                    . 'Remove or reclassify those allocations before rolling back this migration.'
                );
            }
        }

        // Guard BEFORE altering purchase_details.quantity back to integer: fractional quantities must not be truncated
        if (Schema::hasTable('purchase_details') && Schema::hasColumn('purchase_details', 'quantity')) {
            $fractionalCount = DB::table('purchase_details')
                ->whereRaw('ABS(quantity - ROUND(quantity)) > 0.0001')
                ->count();

            if ($fractionalCount > 0) {
                throw new RuntimeException(
                    "Cannot roll back: {$fractionalCount} purchase detail(s) contain fractional quantities. "
                    . 'Rolling back to integer would truncate or corrupt quantity values. '
                    . 'Reconcile or remove those details before rolling back this migration.'
                );
            }
        }

        Schema::dropIfExists('consignment_purchase_detail_lineages');

        if (Schema::hasTable('consignment_receipt_allocations') && Schema::hasColumn('consignment_receipt_allocations', 'tax_snapshot_version')) {
            Schema::table('consignment_receipt_allocations', function (Blueprint $table) {
                $table->dropIndex('idx_cra_tax_snapshot_version');
                $table->dropColumn('tax_snapshot_version');
            });
        }

        if (Schema::hasTable('consignment_billing_confirmations')) {
            Schema::table('consignment_billing_confirmations', function (Blueprint $table) {
                $table->dropUnique('uniq_cbc_purchase_id');

                $table->dropForeign('fk_cbc_billed_by');
                $table->dropForeign('fk_cbc_payment_term');

                $table->dropColumn([
                    'billed_by',
                    'billed_at',
                    'supplier_invoice_number',
                    'invoice_date',
                    'reporting_date',
                    'due_date',
                    'payment_term_id',
                    'tax_ref_no',
                    'billing_notes',
                ]);
            });
        }

        if (Schema::hasTable('purchases') && Schema::hasColumn('purchases', 'source_type')) {
            Schema::table('purchases', function (Blueprint $table) {
                $table->dropIndex('idx_purchases_source_type');
                $table->dropColumn('source_type');
            });
        }

        if (Schema::hasTable('purchase_details') && Schema::hasColumn('purchase_details', 'quantity')) {
            Schema::table('purchase_details', function (Blueprint $table) {
                $table->integer('quantity')->change();
            });
        }
    }
};
