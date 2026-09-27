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
        Schema::create('received_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('po_id')->constrained('purchases')->onDelete('cascade'); // Links to the purchases table
            $table->string('external_delivery_number'); // Supplier delivery number
            $table->string('internal_invoice_number')->nullable(); // Optional internal invoice number
            $table->date('date'); // Date of receipt
            $table->timestamps(); // Laravel's created_at and updated_at columns
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('received_notes');
    }
};
