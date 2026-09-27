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
        Schema::create('purchase_return_goods', function (Blueprint $table) {
            $table->id();

            // link to header
            $table->foreignId('purchase_return_id')
                ->constrained('purchase_returns')
                ->cascadeOnDelete();

            // the product that was received in exchange
            $table->foreignId('product_id')
                ->nullable()
                ->constrained('products')
                ->nullOnDelete();

            // denormalized snapshot to preserve names/codes if catalog changes later
            $table->string('product_name');
            $table->string('product_code')->nullable();

            // quantities & values
            $table->integer('quantity');                    // use base unit
            $table->decimal('unit_value', 15, 2)->nullable();
            $table->decimal('sub_total', 15, 2)->nullable();

            // lifecycle
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            // helpful indexes
            $table->index(['purchase_return_id']);
            $table->index(['product_id']);
        });

        Schema::table('purchase_return_goods', function (Blueprint $table) {
            $table->index(['purchase_return_id', 'received_at'], 'prg_return_received_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_return_goods', function (Blueprint $table) {
            $table->dropIndex('prg_return_received_idx');
        });

        Schema::dropIfExists('purchase_return_goods');
    }
};
