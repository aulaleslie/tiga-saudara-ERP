<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Held custody for standard Sales Returns received under custody policy v1.
 *
 * Additive only: no existing row is changed, nothing is backfilled. Returns received before
 * this change keep NULL policy markers (legacy/unknown) and have no custody rows.
 *
 * Quantities are decimal(15,3), matching the stock tables, so fractional (weight-based) returns are preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sale_returns', 'custody_policy_version')) {
            Schema::table('sale_returns', function (Blueprint $table) {
                // NULL = legacy/unknown policy. Set on FIRST receiving after deployment.
                $table->unsignedSmallInteger('custody_policy_version')->nullable();
                $table->timestamp('custody_policy_applied_at')->nullable();
            });
        }

        if (! Schema::hasTable('sale_return_inspections')) {
            Schema::create('sale_return_inspections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sale_return_id')->constrained('sale_returns')->restrictOnDelete();
                $table->unsignedBigInteger('inspected_by');
                $table->timestamp('inspected_at');
                $table->text('notes');
                $table->decimal('released_quantity', 15, 3);
                // Client-supplied key: the same submission can be applied only once.
                $table->string('request_key', 64)->nullable();
                $table->timestamps();

                $table->unique(['sale_return_id', 'request_key'], 'sr_inspections_request_unique');
            });
        }

        if (! Schema::hasTable('sale_return_custodies')) {
            Schema::create('sale_return_custodies', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sale_return_id')->constrained('sale_returns')->restrictOnDelete();
                $table->foreignId('sale_return_detail_id')->constrained('sale_return_details')->restrictOnDelete();
                $table->unsignedBigInteger('sale_id');
                $table->unsignedBigInteger('dispatch_detail_id')->nullable();
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('owner_setting_id');
                $table->unsignedBigInteger('location_id');
                $table->unsignedBigInteger('tax_id')->nullable();
                $table->unsignedBigInteger('product_serial_number_id')->nullable();
                $table->unsignedSmallInteger('policy_version');
                // held = received - released - dispatched, never negative.
                $table->decimal('quantity_received', 15, 3);
                $table->decimal('quantity_released', 15, 3)->default(0);
                $table->decimal('quantity_dispatched', 15, 3)->default(0);
                // Optimistic counter bumped on every balance change.
                $table->unsignedInteger('version')->default(1);
                // "{detail}:{serial|0}": one physical occurrence can be received exactly once.
                $table->string('occurrence_key', 64);
                $table->timestamps();

                $table->unique('occurrence_key', 'sr_custodies_occurrence_unique');
                $table->index(['sale_return_id', 'sale_return_detail_id'], 'sr_custodies_return_detail_idx');
                $table->index('product_serial_number_id', 'sr_custodies_serial_idx');
                $table->index(['product_id', 'location_id'], 'sr_custodies_stock_idx');
            });

            // Defense in depth for engines that enforce CHECK constraints (MySQL 8.0.16+/MariaDB 10.2+).
            // The service also enforces it under row locks; SQLite cannot add CHECK after creation.
            $driver = DB::connection()->getDriverName();
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                try {
                    DB::statement('ALTER TABLE sale_return_custodies ADD CONSTRAINT sr_custodies_held_nonnegative CHECK (quantity_released + quantity_dispatched <= quantity_received)');
                } catch (\Throwable $e) {
                    // older engines parse but ignore CHECK; the locked service check remains authoritative
                }
            }
        }

        if (! Schema::hasTable('sale_return_custody_movements')) {
            Schema::create('sale_return_custody_movements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sale_return_custody_id')->constrained('sale_return_custodies')->restrictOnDelete();
                $table->unsignedBigInteger('sale_return_id');
                $table->unsignedBigInteger('inspection_id')->nullable();
                // RECEIVED | RELEASED | DISPATCHED (append-only ledger)
                $table->string('type', 16);
                $table->decimal('quantity', 15, 3);
                $table->unsignedBigInteger('product_serial_number_id')->nullable();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->timestamp('occurred_at');
                $table->text('notes')->nullable();
                // Destination bucket for RELEASED movements (what was actually credited).
                $table->unsignedBigInteger('destination_setting_id')->nullable();
                $table->unsignedBigInteger('destination_location_id')->nullable();
                $table->unsignedBigInteger('destination_tax_id')->nullable();
                // Replay guard: one movement per physical event.
                $table->string('movement_key', 96);
                $table->timestamps();

                $table->unique('movement_key', 'sr_custody_movements_key_unique');
                $table->index(['sale_return_id', 'type'], 'sr_custody_movements_return_type_idx');
            });
        }
    }

    public function down(): void
    {
        // Safe only before any custody exists. Once populated the ledger is evidence: refuse to drop it
        // (see the rollback boundary in the repair guide) rather than silently destroying it.
        foreach (['sale_return_custodies', 'sale_return_custody_movements', 'sale_return_inspections'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new \RuntimeException(
                    "Rollback refused: {$table} already holds custody evidence. Disable SALES_RETURN_CUSTODY_ENABLED and fix forward instead."
                );
            }
        }

        Schema::dropIfExists('sale_return_custody_movements');
        Schema::dropIfExists('sale_return_custodies');
        Schema::dropIfExists('sale_return_inspections');

        if (Schema::hasColumn('sale_returns', 'custody_policy_version')) {
            Schema::table('sale_returns', function (Blueprint $table) {
                $table->dropColumn(['custody_policy_version', 'custody_policy_applied_at']);
            });
        }
    }
};
