<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Table for individual payment rows in a checkout
        // One row per payment entry (e.g., cash, card, etc.)
        Schema::create('pos_checkout_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_checkout_id')->constrained('pos_checkouts')->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained('payment_methods')->restrictOnDelete();

            // Payment amount in minor units (cents)
            $table->unsignedBigInteger('amount_minor_units');

            // Optional reference for the payment (check #, card last 4, etc.)
            $table->string('reference')->nullable();

            // Deterministic ordering of payments within a checkout
            $table->unsignedInteger('sequence_order');

            $table->timestamps();

            // Indexes for efficient querying
            $table->index(['pos_checkout_id', 'sequence_order'], 'pos_checkout_payments_checkout_sequence_idx');
            $table->index('payment_method_id', 'pos_checkout_payments_method_idx');
        });

        // Table for payment-to-split-group allocations
        // Maps a portion of a payment to a specific split ownership group
        Schema::create('pos_checkout_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pos_checkout_id')->constrained('pos_checkouts')->cascadeOnDelete();
            $table->foreignId('pos_checkout_payment_id')->constrained('pos_checkout_payments')->cascadeOnDelete();

            // Reference to the split group (source_setting_id + source_location_id + tax_bucket)
            // Stored as composite key identifier
            $table->string('split_group_key');

            // Amount allocated to this group from this payment (in minor units)
            $table->unsignedBigInteger('allocated_amount_minor_units');

            $table->timestamps();

            // Composite indexes for efficient allocation queries
            $table->index(['pos_checkout_id', 'split_group_key'], 'pos_checkout_allocations_checkout_group_idx');
            $table->index(['pos_checkout_payment_id', 'split_group_key'], 'pos_checkout_allocations_payment_group_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_checkout_payment_allocations');
        Schema::dropIfExists('pos_checkout_payments');
    }
};
