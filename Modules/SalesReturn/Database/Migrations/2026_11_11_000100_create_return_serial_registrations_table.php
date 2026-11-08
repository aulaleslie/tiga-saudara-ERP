<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_serial_registrations', function (Blueprint $table) {
            $table->id();
            $table->uuid('claim_uuid')->unique();
            $table->string('workflow', 16); // standard | pos
            $table->unsignedBigInteger('sale_return_id')->nullable();
            $table->unsignedBigInteger('sale_return_detail_id')->nullable();
            $table->unsignedBigInteger('pos_return_id')->nullable();
            $table->unsignedBigInteger('pos_return_line_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->string('source_key', 191);
            $table->unsignedBigInteger('component_product_id')->nullable();
            $table->string('source_classification', 32);
            $table->string('source_basis', 64);
            $table->string('source_fingerprint', 64);
            $table->string('unit_attribution_key', 191);
            $table->string('proposed_serial', 255);
            $table->unsignedBigInteger('registered_serial_id')->nullable()->unique();
            $table->unsignedBigInteger('registered_by')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamps();

            $table->foreign('sale_return_id')->references('id')->on('sale_returns')->cascadeOnDelete();
            $table->foreign('sale_return_detail_id')->references('id')->on('sale_return_details')->cascadeOnDelete();
            $table->foreign('pos_return_id')->references('id')->on('pos_returns')->cascadeOnDelete();
            $table->foreign('pos_return_line_id')->references('id')->on('pos_return_lines')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products');
            $table->foreign('registered_serial_id')->references('id')->on('product_serial_numbers')->restrictOnDelete();

            $table->unique(['workflow', 'unit_attribution_key'], 'rsr_unit_attribution_unique');
            $table->index(['workflow', 'sale_return_id'], 'rsr_standard_idx');
            $table->index(['workflow', 'pos_return_id'], 'rsr_pos_idx');
            $table->index(['product_id', 'proposed_serial'], 'rsr_product_serial_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_serial_registrations');
    }
};
