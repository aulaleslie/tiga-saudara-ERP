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
        Schema::create('serial_number_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_serial_number_id')
                ->constrained('product_serial_numbers')
                ->onDelete('cascade');
            
            $table->string('event_type', 50); // RECEIVED, SOLD, SALE_RETURNED, etc.
            
            $table->foreignId('location_id')
                ->nullable()
                ->constrained('locations')
                ->onDelete('set null');
            
            $table->nullableMorphs('reference');
            
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');
            
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_serial_number_id', 'created_at'], 'idx_snh_serial_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('serial_number_histories');
    }
};
