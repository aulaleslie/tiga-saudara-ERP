<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_route_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('transfers')->cascadeOnDelete();
            $table->unsignedInteger('transfer_revision');
            $table->foreignId('origin_location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('destination_location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('origin_setting_id')->constrained('settings')->restrictOnDelete();
            $table->foreignId('destination_setting_id')->constrained('settings')->restrictOnDelete();
            $table->boolean('origin_is_pkp');
            $table->boolean('destination_is_pkp');
            $table->boolean('same_business');
            $table->string('stock_condition', 20);
            $table->string('destination_classification', 20); // PRESERVE, TAX, NON_TAX
            $table->boolean('mandatory_return');
            $table->foreignId('resolved_tax_id')->nullable()->constrained('taxes')->restrictOnDelete();
            $table->string('resolved_tax_name')->nullable();
            $table->decimal('resolved_tax_rate', 8, 4)->nullable();
            $table->string('tax_resolver_provenance', 20)->nullable(); // DEFAULT, FALLBACK
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at');
            $table->timestamps();

            $table->unique(['transfer_id', 'transfer_revision'], 'transfer_route_policies_transfer_revision_unique');
            $table->index('destination_classification');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_route_policies');
    }
};
