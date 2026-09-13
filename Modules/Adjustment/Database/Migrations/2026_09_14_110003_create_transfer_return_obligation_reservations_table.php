<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_return_obligation_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_movement_return_obligation_id');
            $table->foreignId('transfer_movement_id');
            $table->foreignId('transfer_movement_line_id');
            $table->decimal('quantity', 14, 4);
            $table->string('status', 20)->default('ACTIVE'); // ACTIVE, CLOSED
            $table->foreignId('created_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->foreign('transfer_movement_return_obligation_id', 'tror_obligation_fk')
                ->references('id')->on('transfer_movement_return_obligations')->restrictOnDelete();
            $table->foreign('transfer_movement_id', 'tror_movement_fk')
                ->references('id')->on('transfer_movements')->restrictOnDelete();
            $table->foreign('transfer_movement_line_id', 'tror_line_fk')
                ->references('id')->on('transfer_movement_lines')->restrictOnDelete();
            $table->foreign('created_by', 'tror_creator_fk')
                ->references('id')->on('users')->nullOnDelete();

            // One reservation per approved return-dispatch movement line: prevents double-reserving
            // the same approved line and gives approval a stable row to lock per obligation.
            $table->unique(
                ['transfer_movement_line_id'],
                'transfer_return_obl_reservations_line_unique'
            );
            $table->index(
                ['transfer_movement_return_obligation_id', 'status'],
                'transfer_return_obl_reservations_obligation_status_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_return_obligation_reservations');
    }
};
