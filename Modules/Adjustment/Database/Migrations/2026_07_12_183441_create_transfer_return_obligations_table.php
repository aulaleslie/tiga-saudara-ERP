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
        Schema::create('transfer_return_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transfer_product_id')->constrained()->cascadeOnDelete();
            
            $table->unsignedInteger('required_quantity_tax')->default(0);
            $table->unsignedInteger('required_quantity_broken_tax')->default(0);
            
            $table->unsignedInteger('return_dispatched_quantity_tax')->default(0);
            $table->unsignedInteger('return_dispatched_quantity_broken_tax')->default(0);
            
            $table->unsignedInteger('return_received_quantity_tax')->default(0);
            $table->unsignedInteger('return_received_quantity_broken_tax')->default(0);
            
            $table->json('exact_serialized_obligations')->nullable();
            
            $table->timestamps();
            
            $table->index('transfer_id');
            $table->index('transfer_product_id');
            
            $table->unique(
                ['transfer_id', 'transfer_product_id'],
                'transfer_return_transfer_product_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfer_return_obligations');
    }
};
