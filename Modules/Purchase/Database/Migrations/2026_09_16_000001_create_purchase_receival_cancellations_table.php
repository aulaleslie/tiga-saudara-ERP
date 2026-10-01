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
        // 1. Cancellation metadata on received_notes
        Schema::table('received_notes', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('rejection_reason');
            $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at');
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');
            $table->string('cancellation_origin', 50)->nullable()->after('cancellation_reason');

            $table->foreign('cancelled_by', 'fk_received_notes_cancelled_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index(['status', 'po_id'], 'idx_received_notes_status_po');
        });

        // 2. Immutable detail snapshots on received_note_details
        Schema::table('received_note_details', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable()->after('po_detail_id');
            $table->string('product_code', 100)->nullable()->after('product_id');
            $table->string('product_name', 255)->nullable()->after('product_code');
            $table->unsignedBigInteger('purchase_unit_id')->nullable()->after('product_name');
            $table->string('unit_name', 100)->nullable()->after('purchase_unit_id');
            $table->string('base_unit_name', 100)->nullable()->after('unit_name');
            $table->decimal('conversion_factor', 12, 6)->nullable()->after('base_unit_name');
            $table->decimal('entered_quantity', 15, 3)->nullable()->after('conversion_factor');
            $table->unsignedBigInteger('tax_id')->nullable()->after('entered_quantity');
            $table->unsignedBigInteger('location_id')->nullable()->after('tax_id');

            $table->foreign('product_id', 'fk_rnd_product_id')
                ->references('id')
                ->on('products')
                ->nullOnDelete();
            $table->foreign('purchase_unit_id', 'fk_rnd_purchase_unit_id')
                ->references('id')
                ->on('units')
                ->nullOnDelete();
            $table->foreign('tax_id', 'fk_rnd_tax_id')
                ->references('id')
                ->on('taxes')
                ->nullOnDelete();
            $table->foreign('location_id', 'fk_rnd_location_id')
                ->references('id')
                ->on('locations')
                ->nullOnDelete();

            $table->index('product_id', 'idx_rnd_product_id');
            $table->index('location_id', 'idx_rnd_location_id');
        });

        // 3. received_note_cancellations header table
        Schema::create('received_note_cancellations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('received_note_id');
            $table->unsignedBigInteger('purchase_id');
            $table->unsignedBigInteger('setting_id');
            $table->string('previous_status', 50);
            $table->string('cancellation_origin', 50); // MANUAL_APPROVED, MANUAL_PENDING, AUTO_PURCHASE_REOPEN
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->text('reason');
            $table->timestamp('cancelled_at')->useCurrent();
            $table->timestamps();

            $table->foreign('received_note_id', 'fk_rnc_received_note_id')
                ->references('id')
                ->on('received_notes')
                ->onDelete('cascade');
            $table->foreign('purchase_id', 'fk_rnc_purchase_id')
                ->references('id')
                ->on('purchases')
                ->onDelete('cascade');
            $table->foreign('setting_id', 'fk_rnc_setting_id')
                ->references('id')
                ->on('settings')
                ->onDelete('restrict');
            $table->foreign('cancelled_by', 'fk_rnc_cancelled_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            // Each received note can be cancelled at most once
            $table->unique('received_note_id', 'uniq_rnc_received_note_id');
            $table->index('purchase_id', 'idx_rnc_purchase_id');
            $table->index('setting_id', 'idx_rnc_setting_id');
            $table->index('cancellation_origin', 'idx_rnc_cancellation_origin');
        });

        // 4. received_note_cancellation_details line table
        Schema::create('received_note_cancellation_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cancellation_id');
            $table->unsignedBigInteger('received_note_detail_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('tax_id')->nullable();
            $table->decimal('quantity', 15, 3);
            $table->decimal('quantity_tax', 15, 3)->default(0);
            $table->decimal('quantity_non_tax', 15, 3)->default(0);
            $table->unsignedBigInteger('original_transaction_id')->nullable();
            $table->unsignedBigInteger('reversal_transaction_id')->nullable();
            $table->timestamps();

            $table->foreign('cancellation_id', 'fk_rncd_cancellation_id')
                ->references('id')
                ->on('received_note_cancellations')
                ->onDelete('cascade');
            $table->foreign('received_note_detail_id', 'fk_rncd_rnd_id')
                ->references('id')
                ->on('received_note_details')
                ->onDelete('restrict');
            $table->foreign('product_id', 'fk_rncd_product_id')
                ->references('id')
                ->on('products')
                ->onDelete('restrict');
            $table->foreign('location_id', 'fk_rncd_location_id')
                ->references('id')
                ->on('locations')
                ->onDelete('restrict');
            $table->foreign('tax_id', 'fk_rncd_tax_id')
                ->references('id')
                ->on('taxes')
                ->nullOnDelete();
            $table->foreign('original_transaction_id', 'fk_rncd_orig_txn_id')
                ->references('id')
                ->on('transactions')
                ->nullOnDelete();
            $table->foreign('reversal_transaction_id', 'fk_rncd_rev_txn_id')
                ->references('id')
                ->on('transactions')
                ->nullOnDelete();

            $table->index('cancellation_id', 'idx_rncd_cancellation_id');
            $table->index('received_note_detail_id', 'idx_rncd_rnd_id');
            $table->index('product_id', 'idx_rncd_product_id');
            $table->index('original_transaction_id', 'idx_rncd_orig_txn_id');
            $table->index('reversal_transaction_id', 'idx_rncd_rev_txn_id');
        });

        // 5. Add cancellation detail reference to transactions for reversal provenance
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('received_note_cancellation_detail_id')
                ->nullable()
                ->after('consignment_receiving_detail_id');

            $table->foreign('received_note_cancellation_detail_id', 'fk_transactions_rncd_id')
                ->references('id')
                ->on('received_note_cancellation_details')
                ->nullOnDelete();

            $table->index('received_note_cancellation_detail_id', 'idx_transactions_rncd_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign('fk_transactions_rncd_id');
            $table->dropIndex('idx_transactions_rncd_id');
            $table->dropColumn('received_note_cancellation_detail_id');
        });

        Schema::dropIfExists('received_note_cancellation_details');
        Schema::dropIfExists('received_note_cancellations');

        Schema::table('received_note_details', function (Blueprint $table) {
            $table->dropForeign('fk_rnd_location_id');
            $table->dropForeign('fk_rnd_tax_id');
            $table->dropForeign('fk_rnd_purchase_unit_id');
            $table->dropForeign('fk_rnd_product_id');

            $table->dropIndex('idx_rnd_location_id');
            $table->dropIndex('idx_rnd_product_id');

            $table->dropColumn([
                'product_id',
                'product_code',
                'product_name',
                'purchase_unit_id',
                'unit_name',
                'base_unit_name',
                'conversion_factor',
                'entered_quantity',
                'tax_id',
                'location_id',
            ]);
        });

        Schema::table('received_notes', function (Blueprint $table) {
            $table->dropForeign('fk_received_notes_cancelled_by');
            $table->dropIndex('idx_received_notes_status_po');
            $table->dropColumn([
                'cancelled_at',
                'cancelled_by',
                'cancellation_reason',
                'cancellation_origin',
            ]);
        });
    }
};
