<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('received_note_detail_serial_numbers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('received_note_detail_id');
            $table->unsignedBigInteger('product_serial_number_id');
            $table->unsignedBigInteger('source_history_id')->nullable();
            $table->timestamp('linked_at')->useCurrent();
            $table->timestamps();

            $table->foreign('received_note_detail_id', 'rnd_sn_rnd_foreign')
                ->references('id')->on('received_note_details')
                ->onDelete('cascade');
            
            $table->foreign('product_serial_number_id', 'rnd_sn_psn_foreign')
                ->references('id')->on('product_serial_numbers')
                ->onDelete('cascade');

            $table->unique(['received_note_detail_id', 'product_serial_number_id'], 'rnd_sn_unique');
            $table->index('product_serial_number_id', 'rnd_sn_psn_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('received_note_detail_serial_numbers');
    }
};
