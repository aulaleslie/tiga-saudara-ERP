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
        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('origin_location_id');
            $table->unsignedBigInteger('destination_location_id');
            $table->unsignedBigInteger('created_by'); // User who created the transfer
            $table->unsignedBigInteger('approved_by')->nullable(); // User who approved the transfer
            $table->unsignedBigInteger('rejected_by')->nullable(); // User who rejected the transfer
            $table->unsignedBigInteger('dispatched_by')->nullable(); // User who dispatched the transfer
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED', 'DISPATCHED', 'RECEIVED'])->default('PENDING');
            $table->timestamp('approved_at')->nullable(); // Approval timestamp
            $table->timestamp('rejected_at')->nullable(); // Rejection timestamp
            $table->timestamp('dispatched_at')->nullable(); // Dispatch timestamp
            $table->timestamps(); // General timestamps for record keeping
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};
