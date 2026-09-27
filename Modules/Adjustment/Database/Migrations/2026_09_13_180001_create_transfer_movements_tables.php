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
        Schema::create('transfer_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('transfers')->cascadeOnDelete();
            $table->string('type', 32); // FORWARD_DISPATCH, FORWARD_RECEIPT, RETURN_DISPATCH, RETURN_RECEIPT
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('lock_version')->default(1);
            $table->unsignedInteger('transfer_revision');
            $table->string('status', 32)->default('DRAFT'); // DRAFT, PENDING, APPROVED, REJECTED, CANCELLED
            $table->string('stock_condition', 20); // GOOD, BREAKAGE
            $table->foreignId('origin_location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('destination_location_id')->nullable()->constrained('locations')->restrictOnDelete();
            $table->foreignId('source_movement_id')->nullable()->constrained('transfer_movements')->restrictOnDelete();
            $table->foreignId('supersedes_movement_id')->nullable()->constrained('transfer_movements')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Unique constraint on transfer + type + revision
            $table->unique(['transfer_id', 'type', 'revision'], 'transfer_movements_transfer_type_revision_unique');

            // Lookups
            $table->index(['transfer_id', 'type', 'status'], 'transfer_movements_transfer_type_status_idx');
            $table->index('source_movement_id');
            $table->index('supersedes_movement_id');
            $table->index('created_by');
            $table->index('updated_by');
        });

        Schema::create('transfer_movement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_movement_id')->constrained('transfer_movements')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 14, 4)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['transfer_movement_id', 'product_id'], 'transfer_movement_lines_movement_product_unique');
            $table->index('product_id');
        });

        Schema::create('transfer_movement_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_movement_id')->constrained('transfer_movements')->cascadeOnDelete();
            $table->foreignId('transfer_movement_line_id')->constrained('transfer_movement_lines')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('product_serial_number_id')->nullable()->constrained('product_serial_numbers')->nullOnDelete();
            $table->string('serial_number');
            $table->string('stock_condition', 20); // GOOD, BREAKAGE
            $table->foreignId('tax_id')->nullable()->constrained('taxes')->nullOnDelete();
            $table->string('tax_name')->nullable();
            $table->decimal('tax_rate', 8, 4)->nullable();
            $table->string('transit_custody_status', 32)->default('INACTIVE'); // INACTIVE, IN_TRANSIT, CLOSED
            $table->timestamps();

            $table->unique(['transfer_movement_id', 'serial_number'], 'transfer_movement_serials_movement_serial_unique');
            $table->index(['transfer_movement_line_id', 'product_id'], 'transfer_mvmt_serials_line_prod_idx');
            $table->index('product_serial_number_id');
            $table->index('serial_number');
        });

        Schema::create('transfer_movement_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_movement_id')->constrained('transfer_movements')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('action', 32); // CREATED, UPDATED, SUBMITTED, APPROVED, REJECTED, CANCELLED
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->timestamps();

            $table->index(['transfer_movement_id', 'revision'], 'transfer_movement_histories_movement_revision_idx');
            $table->unique(['transfer_movement_id', 'revision', 'action', 'idempotency_key'], 'transfer_mvmt_hist_scope_idem_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfer_movement_histories');
        Schema::dropIfExists('transfer_movement_serials');
        Schema::dropIfExists('transfer_movement_lines');
        Schema::dropIfExists('transfer_movements');
    }
};
