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
        Schema::create('received_note_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('received_note_id')->constrained('received_notes')->onDelete('cascade'); // Links to received_notes table
            $table->foreignId('po_detail_id')->constrained('purchase_details')->onDelete('cascade'); // Links to purchase_details table
            $table->integer('quantity_received'); // Quantity received for this item
            $table->timestamps(); // Laravel's created_at and updated_at columns
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('received_note_details');
    }
};
