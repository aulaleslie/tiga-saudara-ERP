<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Receipt-rooted consignment custody provenance (track-transferred-consignment-provenance 1.1).
 *
 * Identities kept separate on every row:
 *  - original_setting_id / supplier_id: immutable original receipt billing business and supplier
 *  - custody (location/setting): where the receipt-backed goods physically are
 *  - seller (location/setting): actual DispatchDetail business/location
 *  - acquired owner: holding business after unsold buyout
 *
 * Additive only. Rollback refuses to drop populated tables.
 */
return new class extends Migration
{
    // Drop order: children before the mapping table they reference.
    private const TABLES = [
        'consignment_provenance_events',
        'consignment_obligation_bindings',
        'consignment_transfer_lot_selections',
        'consignment_historical_mappings',
    ];

    public function up(): void
    {
        // Explicit receipt-lot quantities chosen for a consignment transfer (never for ordinary transfers).
        Schema::create('consignment_transfer_lot_selections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transfer_id');
            $table->unsignedBigInteger('consignment_receiving_detail_id');
            $table->unsignedBigInteger('original_setting_id');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_serial_number_id')->nullable();
            $table->unsignedBigInteger('serial_key')->storedAs('COALESCE(product_serial_number_id, 0)');
            $table->unsignedBigInteger('source_location_id');
            $table->unsignedBigInteger('source_setting_id');
            $table->unsignedBigInteger('destination_location_id');
            $table->unsignedBigInteger('destination_setting_id');
            $table->decimal('base_quantity', 15, 3);
            $table->unsignedInteger('selection_revision')->default(1);
            $table->string('status', 20)->default('PROPOSED'); // PROPOSED, CONFIRMED, VOIDED
            $table->string('selection_mode', 20)->default('EXPLICIT'); // SOLE_LOT, EXPLICIT, SERIAL
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->foreign('transfer_id', 'fk_ctls_transfer')->references('id')->on('transfers')->onDelete('restrict');
            $table->foreign('consignment_receiving_detail_id', 'fk_ctls_crd')->references('id')->on('consignment_receiving_details')->onDelete('restrict');
            $table->foreign('original_setting_id', 'fk_ctls_orig_setting')->references('id')->on('settings')->onDelete('restrict');
            $table->foreign('supplier_id', 'fk_ctls_supplier')->references('id')->on('suppliers')->onDelete('restrict');
            $table->foreign('product_id', 'fk_ctls_product')->references('id')->on('products')->onDelete('restrict');
            $table->foreign('product_serial_number_id', 'fk_ctls_serial')->references('id')->on('product_serial_numbers')->onDelete('restrict');
            $table->foreign('source_location_id', 'fk_ctls_src_loc')->references('id')->on('locations')->onDelete('restrict');
            $table->foreign('source_setting_id', 'fk_ctls_src_setting')->references('id')->on('settings')->onDelete('restrict');
            $table->foreign('destination_location_id', 'fk_ctls_dst_loc')->references('id')->on('locations')->onDelete('restrict');
            $table->foreign('destination_setting_id', 'fk_ctls_dst_setting')->references('id')->on('settings')->onDelete('restrict');
            $table->foreign('confirmed_by', 'fk_ctls_actor')->references('id')->on('users')->nullOnDelete();

            $table->unique(['transfer_id', 'selection_revision', 'consignment_receiving_detail_id', 'serial_key', 'source_location_id', 'destination_location_id'], 'uniq_ctls_transfer_rev_lot_route');
            $table->index(['consignment_receiving_detail_id', 'status'], 'idx_ctls_crd_status');
        });

        // Append-only custody events; one row per exact source event (idempotent replay).
        Schema::create('consignment_provenance_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consignment_receiving_detail_id');
            $table->unsignedBigInteger('original_setting_id');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('product_serial_number_id')->nullable();
            // RECEIPT, TRANSFER_DISPATCH, TRANSFER_RECEIPT, TRANSFER_CANCEL, TRANSFER_RETURN,
            // SALE, SALE_RETURN, HELD_RELEASE, BUYOUT_ACQUISITION, BUYOUT_REVERSAL, HISTORICAL_MAPPING
            $table->string('kind', 32);
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->string('source_event_key', 191);
            // States: SELLABLE, HELD, TRANSIT, SOLD, ACQUIRED, EXTERNAL
            $table->string('from_state', 20);
            $table->unsignedBigInteger('from_location_id')->nullable();
            $table->unsignedBigInteger('from_setting_id')->nullable();
            $table->string('to_state', 20);
            $table->unsignedBigInteger('to_location_id')->nullable();
            $table->unsignedBigInteger('to_setting_id')->nullable();
            $table->unsignedBigInteger('acquired_owner_setting_id')->nullable();
            $table->string('stock_condition', 20)->nullable(); // GOOD, BREAKAGE
            $table->string('tax_bucket', 20)->nullable(); // TAX, NON_TAX
            $table->decimal('base_quantity', 15, 3); // always positive; direction from from/to
            $table->unsignedBigInteger('historical_mapping_id')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('effective_at');
            $table->timestamps();

            $table->foreign('consignment_receiving_detail_id', 'fk_cpe_crd')->references('id')->on('consignment_receiving_details')->onDelete('restrict');
            $table->foreign('original_setting_id', 'fk_cpe_orig_setting')->references('id')->on('settings')->onDelete('restrict');
            $table->foreign('supplier_id', 'fk_cpe_supplier')->references('id')->on('suppliers')->onDelete('restrict');
            $table->foreign('product_id', 'fk_cpe_product')->references('id')->on('products')->onDelete('restrict');
            $table->foreign('product_serial_number_id', 'fk_cpe_serial')->references('id')->on('product_serial_numbers')->onDelete('restrict');
            $table->foreign('from_location_id', 'fk_cpe_from_loc')->references('id')->on('locations')->onDelete('restrict');
            $table->foreign('from_setting_id', 'fk_cpe_from_setting')->references('id')->on('settings')->onDelete('restrict');
            $table->foreign('to_location_id', 'fk_cpe_to_loc')->references('id')->on('locations')->onDelete('restrict');
            $table->foreign('to_setting_id', 'fk_cpe_to_setting')->references('id')->on('settings')->onDelete('restrict');
            $table->foreign('acquired_owner_setting_id', 'fk_cpe_owner_setting')->references('id')->on('settings')->onDelete('restrict');
            $table->foreign('actor_id', 'fk_cpe_actor')->references('id')->on('users')->nullOnDelete();

            $table->unique(['source_event_key'], 'uniq_cpe_source_event');
            $table->index(['consignment_receiving_detail_id', 'effective_at'], 'idx_cpe_crd_effective');
            $table->index(['product_id', 'to_location_id'], 'idx_cpe_product_to_loc');
            $table->index(['product_serial_number_id'], 'idx_cpe_serial');
            $table->index(['source_type', 'source_id'], 'idx_cpe_source');
        });

        // Supplemental receipt obligation for a sold envelope; the envelope (and its hash) is never rewritten.
        Schema::create('consignment_obligation_bindings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consignment_sold_source_id');
            $table->unsignedBigInteger('consignment_receiving_detail_id');
            $table->unsignedBigInteger('original_setting_id');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('seller_setting_id');
            $table->unsignedBigInteger('seller_location_id');
            $table->decimal('base_quantity', 15, 3);
            $table->json('serial_identities')->nullable();
            $table->json('custody_path')->nullable();
            $table->string('binding_version', 10)->default('b1');
            $table->string('binding_hash', 64);
            $table->unsignedInteger('revision')->default(1);
            $table->string('status', 20)->default('ACTIVE'); // ACTIVE, SUPERSEDED
            $table->string('attribution_mode', 20)->default('SERIAL'); // SERIAL, CONFIRMED_LOT, LOCAL
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->foreign('consignment_sold_source_id', 'fk_cob_source')->references('id')->on('consignment_sold_sources')->onDelete('restrict');
            $table->foreign('consignment_receiving_detail_id', 'fk_cob_crd')->references('id')->on('consignment_receiving_details')->onDelete('restrict');
            $table->foreign('original_setting_id', 'fk_cob_orig_setting')->references('id')->on('settings')->onDelete('restrict');
            $table->foreign('supplier_id', 'fk_cob_supplier')->references('id')->on('suppliers')->onDelete('restrict');
            $table->foreign('product_id', 'fk_cob_product')->references('id')->on('products')->onDelete('restrict');
            $table->foreign('seller_setting_id', 'fk_cob_seller_setting')->references('id')->on('settings')->onDelete('restrict');
            $table->foreign('seller_location_id', 'fk_cob_seller_loc')->references('id')->on('locations')->onDelete('restrict');
            $table->foreign('confirmed_by', 'fk_cob_actor')->references('id')->on('users')->nullOnDelete();

            $table->unique(['consignment_sold_source_id', 'consignment_receiving_detail_id', 'revision'], 'uniq_cob_source_receipt_rev');
            $table->index(['original_setting_id', 'status'], 'idx_cob_orig_status');
            $table->index(['consignment_receiving_detail_id', 'status'], 'idx_cob_crd_status');
        });

        // Preview-first, operator-confirmed historical reconstruction decisions (immutable revisions).
        Schema::create('consignment_historical_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('original_setting_id');
            $table->unsignedBigInteger('consignment_receiving_detail_id');
            $table->unsignedInteger('revision')->default(1);
            $table->string('evidence_hash', 64);
            $table->json('mapping');
            $table->json('source_references')->nullable();
            $table->json('unresolved_blockers')->nullable();
            $table->string('status', 20)->default('CONFIRMED'); // CONFIRMED, SUPERSEDED
            $table->text('reason');
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at');
            $table->timestamps();

            $table->foreign('original_setting_id', 'fk_chm_orig_setting')->references('id')->on('settings')->onDelete('restrict');
            $table->foreign('consignment_receiving_detail_id', 'fk_chm_crd')->references('id')->on('consignment_receiving_details')->onDelete('restrict');
            $table->foreign('confirmed_by', 'fk_chm_actor')->references('id')->on('users')->nullOnDelete();

            $table->unique(['consignment_receiving_detail_id', 'revision'], 'uniq_chm_crd_rev');
            $table->unique(['consignment_receiving_detail_id', 'evidence_hash'], 'uniq_chm_crd_evidence');
        });

        Schema::table('consignment_provenance_events', function (Blueprint $table) {
            $table->foreign('historical_mapping_id', 'fk_cpe_mapping')->references('id')->on('consignment_historical_mappings')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException("Refusing to roll back: {$table} contains provenance evidence. Forward-fix instead.");
            }
        }

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }
};
