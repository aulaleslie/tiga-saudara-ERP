<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consignment_buyouts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('setting_id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('reference', 100);
            // DRAFT, WAITING_APPROVAL, APPROVED, REJECTED, CANCELLED, FINALIZED, REVERSED
            $table->string('status', 50)->default('DRAFT');
            $table->unsignedInteger('revision')->default(1);
            $table->string('approved_revision_hash', 64)->nullable();
            $table->date('date');
            $table->json('invoice_metadata')->nullable();
            $table->json('pricing_intent')->nullable();
            $table->text('notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('finalized_by')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->timestamp('reversed_at')->nullable();
            // Nullable but unique: at most one Purchase per buyout, one buyout per Purchase.
            $table->unsignedBigInteger('purchase_id')->nullable();
            $table->unsignedBigInteger('reversal_id')->nullable();
            $table->timestamps();

            $table->foreign('setting_id', 'fk_cbo_setting')->references('id')->on('settings')->onDelete('restrict');
            $table->foreign('supplier_id', 'fk_cbo_supplier')->references('id')->on('suppliers')->onDelete('restrict');
            foreach (['created_by', 'submitted_by', 'approved_by', 'rejected_by', 'cancelled_by', 'finalized_by', 'reversed_by'] as $col) {
                $table->foreign($col, 'fk_cbo_' . $col)->references('id')->on('users')->nullOnDelete();
            }
            $table->foreign('purchase_id', 'fk_cbo_purchase')->references('id')->on('purchases')->onDelete('restrict');

            $table->unique(['setting_id', 'reference'], 'uniq_cbo_setting_ref');
            $table->unique(['purchase_id'], 'uniq_cbo_purchase');
            $table->unique(['reversal_id'], 'uniq_cbo_reversal');
            $table->index(['setting_id', 'supplier_id', 'status'], 'idx_cbo_setting_supp_status');
        });

        Schema::create('consignment_buyout_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consignment_buyout_id');
            $table->string('line_type', 30); // SOLD_UNBILLED, UNSOLD_BUYOUT
            $table->unsignedBigInteger('consignment_receiving_detail_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('consignment_sold_source_id')->nullable();
            $table->unsignedBigInteger('source_location_id');
            // UNSOLD_BUYOUT: where stock moves. SOLD_UNBILLED: future-return destination. Standard, same business.
            $table->unsignedBigInteger('destination_location_id')->nullable();
            $table->decimal('base_quantity', 15, 3);
            // Non-null mirror of the nullable sold source so uniqueness also covers unsold slices.
            $table->unsignedBigInteger('sold_source_key')->storedAs('COALESCE(consignment_sold_source_id, 0)');
            $table->unsignedBigInteger('destination_key')->storedAs('COALESCE(destination_location_id, 0)');
            $table->json('original_snapshot');
            $table->json('accepted_snapshot')->nullable();
            $table->timestamps();

            $table->foreign('consignment_buyout_id', 'fk_cbol_buyout')->references('id')->on('consignment_buyouts')->onDelete('restrict');
            $table->foreign('consignment_receiving_detail_id', 'fk_cbol_crd')->references('id')->on('consignment_receiving_details')->onDelete('restrict');
            $table->foreign('product_id', 'fk_cbol_product')->references('id')->on('products')->onDelete('restrict');
            $table->foreign('consignment_sold_source_id', 'fk_cbol_sold_source')->references('id')->on('consignment_sold_sources')->onDelete('restrict');
            $table->foreign('source_location_id', 'fk_cbol_src_loc')->references('id')->on('locations')->onDelete('restrict');
            $table->foreign('destination_location_id', 'fk_cbol_dst_loc')->references('id')->on('locations')->onDelete('restrict');

            // A slice is one receipt + type + sold source (0 for unsold) + source location + destination (0 if unset).
            $table->unique(['consignment_buyout_id', 'line_type', 'consignment_receiving_detail_id', 'sold_source_key', 'source_location_id', 'destination_key'], 'uniq_cbol_slice');
            $table->index(['consignment_receiving_detail_id'], 'idx_cbol_crd');
            $table->index(['consignment_sold_source_id'], 'idx_cbol_sold_source');
            $table->index(['product_id', 'source_location_id'], 'idx_cbol_product_loc');
        });

        Schema::create('consignment_buyout_serials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consignment_buyout_id');
            $table->unsignedBigInteger('consignment_buyout_line_id');
            $table->unsignedBigInteger('product_serial_number_id');
            $table->unsignedBigInteger('consignment_receiving_detail_id');
            $table->timestamps();

            $table->foreign('consignment_buyout_id', 'fk_cbos_buyout')->references('id')->on('consignment_buyouts')->onDelete('restrict');
            $table->foreign('consignment_buyout_line_id', 'fk_cbos_line')->references('id')->on('consignment_buyout_lines')->onDelete('restrict');
            $table->foreign('product_serial_number_id', 'fk_cbos_serial')->references('id')->on('product_serial_numbers')->onDelete('restrict');
            $table->foreign('consignment_receiving_detail_id', 'fk_cbos_crd')->references('id')->on('consignment_receiving_details')->onDelete('restrict');

            $table->unique(['consignment_buyout_id', 'product_serial_number_id'], 'uniq_cbos_buyout_serial');
            $table->index(['product_serial_number_id'], 'idx_cbos_serial');
        });

        Schema::create('consignment_buyout_audit_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consignment_buyout_id');
            $table->string('action', 50);
            $table->string('from_status', 50)->nullable();
            $table->string('to_status', 50)->nullable();
            $table->unsignedInteger('revision')->nullable();
            $table->string('revision_hash', 64)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('reason')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamp('created_at')->nullable(); // append-only: no updated_at

            $table->foreign('consignment_buyout_id', 'fk_cbae_buyout')->references('id')->on('consignment_buyouts')->onDelete('restrict');
            $table->foreign('actor_id', 'fk_cbae_actor')->references('id')->on('users')->nullOnDelete();
            $table->index(['consignment_buyout_id', 'id'], 'idx_cbae_buyout');
        });

        Schema::create('consignment_sold_receipt_attributions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('setting_id');
            $table->unsignedBigInteger('consignment_sold_source_id');
            $table->unsignedBigInteger('consignment_receiving_detail_id');
            $table->decimal('attributed_base_quantity', 15, 3);
            // Physically effective customer return explicitly mapped to this receipt (non-serialized).
            $table->decimal('returned_base_quantity', 15, 3)->default(0);
            $table->decimal('returned_acquired_quantity', 15, 3)->default(0);
            $table->unsignedInteger('revision')->default(1);
            $table->string('origin', 30)->default('EXPLICIT'); // EXPLICIT, LEGACY_ALLOCATION
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->foreign('setting_id', 'fk_csra_setting')->references('id')->on('settings')->onDelete('restrict');
            $table->foreign('consignment_sold_source_id', 'fk_csra_source')->references('id')->on('consignment_sold_sources')->onDelete('restrict');
            $table->foreign('consignment_receiving_detail_id', 'fk_csra_crd')->references('id')->on('consignment_receiving_details')->onDelete('restrict');
            $table->foreign('actor_id', 'fk_csra_actor')->references('id')->on('users')->nullOnDelete();

            $table->unique(['consignment_sold_source_id', 'consignment_receiving_detail_id'], 'uniq_csra_source_crd');
            $table->index(['consignment_receiving_detail_id'], 'idx_csra_crd');
            $table->index(['setting_id'], 'idx_csra_setting');
        });

        Schema::create('consignment_buyout_reservations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consignment_buyout_id');
            $table->unsignedBigInteger('consignment_buyout_line_id');
            $table->unsignedInteger('revision');
            // RECEIPT, SOLD_SOURCE, PHYSICAL_BUCKET, SERIAL
            $table->string('resource_type', 30);
            $table->unsignedBigInteger('consignment_receiving_detail_id')->nullable();
            $table->unsignedBigInteger('consignment_sold_source_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('product_serial_number_id')->nullable();
            $table->string('condition_bucket', 30)->nullable(); // good/damaged/...
            $table->string('tax_bucket', 30)->nullable(); // taxed / non-taxed
            $table->decimal('base_quantity', 15, 3)->default(0);
            $table->string('status', 20)->default('ACTIVE'); // ACTIVE, CONSUMED, RELEASED
            $table->timestamp('released_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->foreign('consignment_buyout_id', 'fk_cbor_buyout')->references('id')->on('consignment_buyouts')->onDelete('restrict');
            $table->foreign('consignment_buyout_line_id', 'fk_cbor_line')->references('id')->on('consignment_buyout_lines')->onDelete('restrict');
            $table->foreign('consignment_receiving_detail_id', 'fk_cbor_crd')->references('id')->on('consignment_receiving_details')->onDelete('restrict');
            $table->foreign('consignment_sold_source_id', 'fk_cbor_sold')->references('id')->on('consignment_sold_sources')->onDelete('restrict');
            $table->foreign('location_id', 'fk_cbor_loc')->references('id')->on('locations')->onDelete('restrict');
            $table->foreign('product_id', 'fk_cbor_product')->references('id')->on('products')->onDelete('restrict');
            $table->foreign('product_serial_number_id', 'fk_cbor_serial')->references('id')->on('product_serial_numbers')->onDelete('restrict');

            $table->index(['consignment_buyout_id', 'status'], 'idx_cbor_buyout_status');
            $table->index(['status', 'resource_type', 'product_id', 'location_id'], 'idx_cbor_resource');
            $table->index(['consignment_receiving_detail_id', 'status'], 'idx_cbor_crd_status');
            $table->index(['consignment_sold_source_id', 'status'], 'idx_cbor_sold_status');
        });
    }

    public function down(): void
    {
        $this->refuseWhenEvidenceExists();

        Schema::dropIfExists('consignment_buyout_reservations');
        Schema::dropIfExists('consignment_sold_receipt_attributions');
        Schema::dropIfExists('consignment_buyout_audit_events');
        Schema::dropIfExists('consignment_buyout_serials');
        Schema::dropIfExists('consignment_buyout_lines');
        Schema::dropIfExists('consignment_buyouts');
    }

    private function refuseWhenEvidenceExists(): void
    {
        foreach (['consignment_buyouts', 'consignment_buyout_lines', 'consignment_buyout_serials', 'consignment_buyout_audit_events', 'consignment_sold_receipt_attributions', 'consignment_buyout_reservations'] as $t) {
            if (Schema::hasTable($t) && DB::table($t)->exists()) {
                throw new \RuntimeException("Refusing to roll back: {$t} contains buyout evidence. Disable buyout actions and forward-fix or reverse instead.");
            }
        }
        if (Schema::hasTable('consignment_active_serial_claims')
            && Schema::hasColumn('consignment_active_serial_claims', 'consignment_buyout_id')
            && DB::table('consignment_active_serial_claims')->whereNotNull('consignment_buyout_id')->exists()) {
            throw new \RuntimeException('Refusing to roll back: buyout-owned serial claims exist.');
        }
    }
};
