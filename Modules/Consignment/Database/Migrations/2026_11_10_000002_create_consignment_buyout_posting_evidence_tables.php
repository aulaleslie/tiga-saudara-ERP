<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1.2 Purchase lineage: one row per buyout slice (or serial) on a Purchase detail.
        Schema::create('consignment_buyout_purchase_lineages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consignment_buyout_id');
            $table->unsignedBigInteger('consignment_buyout_line_id');
            $table->unsignedBigInteger('purchase_id');
            $table->unsignedBigInteger('purchase_detail_id');
            $table->unsignedBigInteger('consignment_receiving_detail_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('base_quantity', 15, 3);
            $table->decimal('original_amount', 15, 2)->default(0);
            $table->decimal('accepted_amount', 15, 2)->default(0);
            $table->decimal('inventory_cost_amount', 15, 2)->default(0);
            $table->json('allocation_snapshot')->nullable();
            $table->timestamps();

            $table->foreign('consignment_buyout_id', 'fk_cbpl_buyout')->references('id')->on('consignment_buyouts')->onDelete('restrict');
            $table->foreign('consignment_buyout_line_id', 'fk_cbpl_line')->references('id')->on('consignment_buyout_lines')->onDelete('restrict');
            $table->foreign('purchase_id', 'fk_cbpl_purchase')->references('id')->on('purchases')->onDelete('restrict');
            $table->foreign('purchase_detail_id', 'fk_cbpl_detail')->references('id')->on('purchase_details')->onDelete('restrict');
            $table->foreign('consignment_receiving_detail_id', 'fk_cbpl_crd')->references('id')->on('consignment_receiving_details')->onDelete('restrict');
            $table->foreign('product_id', 'fk_cbpl_product')->references('id')->on('products')->onDelete('restrict');

            // One lineage row per slice: duplicate posting is impossible.
            $table->unique(['consignment_buyout_line_id'], 'uniq_cbpl_line');
            $table->index(['purchase_id'], 'idx_cbpl_purchase');
            $table->index(['purchase_detail_id'], 'idx_cbpl_detail');
        });

        // Signed movement evidence: one leg per slice per direction.
        Schema::create('consignment_buyout_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consignment_buyout_id');
            $table->unsignedBigInteger('consignment_buyout_line_id');
            $table->string('direction', 10); // OUT, IN
            $table->string('kind', 20)->default('ACQUISITION'); // ACQUISITION, REVERSAL
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->decimal('base_quantity', 15, 3); // signed
            $table->decimal('quantity_tax', 15, 3)->default(0);
            $table->decimal('quantity_non_tax', 15, 3)->default(0);
            $table->decimal('quantity_broken_tax', 15, 3)->default(0);
            $table->decimal('quantity_broken_non_tax', 15, 3)->default(0);
            $table->decimal('balance_before', 15, 3)->nullable();
            $table->decimal('balance_after', 15, 3)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('posted_at');
            $table->timestamps();

            $table->foreign('consignment_buyout_id', 'fk_cbm_buyout')->references('id')->on('consignment_buyouts')->onDelete('restrict');
            $table->foreign('consignment_buyout_line_id', 'fk_cbm_line')->references('id')->on('consignment_buyout_lines')->onDelete('restrict');
            $table->foreign('location_id', 'fk_cbm_loc')->references('id')->on('locations')->onDelete('restrict');
            $table->foreign('product_id', 'fk_cbm_product')->references('id')->on('products')->onDelete('restrict');
            $table->foreign('actor_id', 'fk_cbm_actor')->references('id')->on('users')->nullOnDelete();

            $table->unique(['consignment_buyout_line_id', 'kind', 'direction'], 'uniq_cbm_line_kind_dir');
            $table->index(['product_id', 'location_id', 'posted_at'], 'idx_cbm_product_loc');
        });

        // Setting-scoped carrying-value events (zero physical quantity).
        Schema::create('consignment_buyout_cost_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consignment_buyout_id');
            $table->unsignedBigInteger('setting_id');
            $table->unsignedBigInteger('product_id');
            $table->string('kind', 20)->default('ACQUISITION'); // ACQUISITION, REVERSAL
            $table->decimal('total_quantity', 15, 3);
            $table->decimal('acquired_quantity', 15, 3);
            $table->decimal('acquired_cost_total', 15, 2);
            $table->decimal('average_before', 15, 2);
            $table->decimal('average_after', 15, 2);
            $table->decimal('rounding_residual', 15, 4)->default(0);
            // Last product_price_cost_events id right after this event's own write: later ledger rows are intervening changes.
            $table->unsignedBigInteger('price_event_id')->nullable();
            $table->timestamp('effective_at');
            $table->timestamps();

            $table->foreign('consignment_buyout_id', 'fk_cbce_buyout')->references('id')->on('consignment_buyouts')->onDelete('restrict');
            $table->foreign('setting_id', 'fk_cbce_setting')->references('id')->on('settings')->onDelete('restrict');
            $table->foreign('product_id', 'fk_cbce_product')->references('id')->on('products')->onDelete('restrict');

            $table->unique(['consignment_buyout_id', 'product_id', 'kind'], 'uniq_cbce_buyout_product_kind');
            $table->index(['setting_id', 'product_id', 'effective_at'], 'idx_cbce_setting_product');
        });

        // Ownership (serial) event: current acquisition history without clearing receipt lineage.
        Schema::create('consignment_buyout_ownership_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consignment_buyout_id');
            $table->unsignedBigInteger('consignment_buyout_line_id');
            $table->unsignedBigInteger('product_serial_number_id')->nullable();
            $table->unsignedBigInteger('consignment_receiving_detail_id');
            $table->string('kind', 20)->default('ACQUISITION'); // ACQUISITION, REVERSAL
            $table->decimal('base_quantity', 15, 3);
            $table->unsignedBigInteger('serial_key')->storedAs('COALESCE(product_serial_number_id, 0)');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign('consignment_buyout_id', 'fk_cboe_buyout')->references('id')->on('consignment_buyouts')->onDelete('restrict');
            $table->foreign('consignment_buyout_line_id', 'fk_cboe_line')->references('id')->on('consignment_buyout_lines')->onDelete('restrict');
            $table->foreign('product_serial_number_id', 'fk_cboe_serial')->references('id')->on('product_serial_numbers')->onDelete('restrict');
            $table->foreign('consignment_receiving_detail_id', 'fk_cboe_crd')->references('id')->on('consignment_receiving_details')->onDelete('restrict');

            $table->unique(['consignment_buyout_line_id', 'serial_key', 'kind'], 'uniq_cboe_line_serial_kind');
            $table->index(['product_serial_number_id'], 'idx_cboe_serial');
        });

        // One reversal per finalization.
        Schema::create('consignment_buyout_reversals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('consignment_buyout_id');
            $table->unsignedBigInteger('purchase_id');
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->text('reason');
            $table->json('eligibility_snapshot')->nullable();
            $table->timestamp('reversed_at');
            $table->timestamps();

            $table->foreign('consignment_buyout_id', 'fk_cbrv_buyout')->references('id')->on('consignment_buyouts')->onDelete('restrict');
            $table->foreign('purchase_id', 'fk_cbrv_purchase')->references('id')->on('purchases')->onDelete('restrict');
            $table->foreign('reversed_by', 'fk_cbrv_actor')->references('id')->on('users')->nullOnDelete();

            $table->unique(['consignment_buyout_id'], 'uniq_cbrv_buyout');
            $table->unique(['purchase_id'], 'uniq_cbrv_purchase');
        });

        Schema::table('consignment_buyouts', function (Blueprint $table) {
            $table->foreign('reversal_id', 'fk_cbo_reversal')->references('id')->on('consignment_buyout_reversals')->onDelete('restrict');
        });

        // 1.3 Shared active serial claims: add buyout owner without weakening legacy FKs.
        Schema::table('consignment_active_serial_claims', function (Blueprint $table) {
            $table->string('owner_type', 20)->default('LEGACY')->after('id'); // LEGACY, BUYOUT
            $table->unsignedBigInteger('consignment_buyout_id')->nullable();
            $table->unsignedBigInteger('consignment_buyout_serial_id')->nullable();
        });
        // Legacy columns become nullable (buyout-owned rows have no confirmation); FKs stay intact.
        Schema::table('consignment_active_serial_claims', function (Blueprint $table) {
            $table->unsignedBigInteger('consignment_billing_confirmation_id')->nullable()->change();
            $table->unsignedBigInteger('consignment_serialized_allocation_id')->nullable()->change();
        });
        Schema::table('consignment_active_serial_claims', function (Blueprint $table) {
            $table->foreign('consignment_buyout_id', 'fk_casc_buyout')->references('id')->on('consignment_buyouts')->onDelete('restrict');
            $table->foreign('consignment_buyout_serial_id', 'fk_casc_buyout_serial')->references('id')->on('consignment_buyout_serials')->onDelete('restrict');
            $table->unique(['consignment_buyout_serial_id'], 'uniq_casc_buyout_serial');
        });

        // Exactly one complete owner per claim, enforced by the database.
        $this->addClaimOwnerInvariant();

        // 1.4 Setting/product coordination rows (the lock anchors).
        Schema::create('consignment_inventory_coordinations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('setting_id');
            $table->unsignedBigInteger('product_id');
            $table->timestamps();

            $table->foreign('setting_id', 'fk_cic_setting')->references('id')->on('settings')->onDelete('cascade');
            $table->foreign('product_id', 'fk_cic_product')->references('id')->on('products')->onDelete('cascade');
            $table->unique(['setting_id', 'product_id'], 'uniq_cic_setting_product');
        });
    }

    private const OWNER_RULE = "(owner_type = 'LEGACY' AND consignment_billing_confirmation_id IS NOT NULL AND consignment_serialized_allocation_id IS NOT NULL AND consignment_buyout_id IS NULL AND consignment_buyout_serial_id IS NULL)"
        . " OR (owner_type = 'BUYOUT' AND consignment_buyout_id IS NOT NULL AND consignment_buyout_serial_id IS NOT NULL AND consignment_billing_confirmation_id IS NULL AND consignment_serialized_allocation_id IS NULL)";

    private function addClaimOwnerInvariant(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            // SQLite cannot add CHECK constraints after creation; enforce the same rule with triggers.
            foreach (['INSERT', 'UPDATE'] as $op) {
                $name = 'trg_casc_owner_' . strtolower($op);
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$op} ON consignment_active_serial_claims
                    FOR EACH ROW WHEN NOT (" . str_replace(['owner_type', 'consignment_'], ['NEW.owner_type', 'NEW.consignment_'], self::OWNER_RULE) . ")
                    BEGIN SELECT RAISE(ABORT, 'consignment_active_serial_claims owner invariant violated'); END");
            }
        } else {
            DB::statement('ALTER TABLE consignment_active_serial_claims ADD CONSTRAINT chk_casc_owner CHECK (' . self::OWNER_RULE . ')');
        }
    }

    private function dropClaimOwnerInvariant(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_casc_owner_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS trg_casc_owner_update');
        } else {
            DB::statement('ALTER TABLE consignment_active_serial_claims DROP CONSTRAINT chk_casc_owner');
        }
    }

    public function down(): void
    {
        // 1.5 Refuse to destroy populated evidence; do this before any DDL so nothing is half-dropped.
        $evidence = [
            'consignment_buyout_purchase_lineages', 'consignment_buyout_movements', 'consignment_buyout_cost_events',
            'consignment_buyout_ownership_events', 'consignment_buyout_reversals',
        ];
        foreach ($evidence as $t) {
            if (Schema::hasTable($t) && DB::table($t)->exists()) {
                throw new \RuntimeException("Refusing to roll back: {$t} contains buyout posting evidence.");
            }
        }
        if (Schema::hasTable('consignment_active_serial_claims')
            && Schema::hasColumn('consignment_active_serial_claims', 'owner_type')
            && DB::table('consignment_active_serial_claims')->where('owner_type', 'BUYOUT')->exists()) {
            throw new \RuntimeException('Refusing to roll back: buyout-owned serial claims exist.');
        }
        if (Schema::hasTable('consignment_buyouts') && DB::table('consignment_buyouts')->exists()) {
            throw new \RuntimeException('Refusing to roll back: buyout documents exist.');
        }

        Schema::dropIfExists('consignment_inventory_coordinations');

        $this->dropClaimOwnerInvariant();
        Schema::table('consignment_active_serial_claims', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign('fk_casc_buyout_serial');
                $table->dropForeign('fk_casc_buyout');
            }
            $table->dropUnique('uniq_casc_buyout_serial');
        });
        Schema::table('consignment_active_serial_claims', function (Blueprint $table) {
            $table->dropColumn(['owner_type', 'consignment_buyout_id', 'consignment_buyout_serial_id']);
        });
        // Legacy columns stay nullable: restoring NOT NULL is unsafe once rows may exist; legacy rows always populate them.

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('consignment_buyouts', function (Blueprint $table) {
                $table->dropForeign('fk_cbo_reversal');
            });
        }
        Schema::dropIfExists('consignment_buyout_reversals');
        Schema::dropIfExists('consignment_buyout_ownership_events');
        Schema::dropIfExists('consignment_buyout_cost_events');
        Schema::dropIfExists('consignment_buyout_movements');
        Schema::dropIfExists('consignment_buyout_purchase_lineages');
    }
};
