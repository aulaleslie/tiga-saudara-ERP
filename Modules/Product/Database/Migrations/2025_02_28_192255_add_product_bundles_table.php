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
        Schema::create('product_bundles', function (Blueprint $table) {
            $table->id();
            // The parent product that this bundle belongs to
            $table->unsignedBigInteger('parent_product_id');
            // A name to differentiate each bundle for a given product
            $table->string('name');
            // Optional: a description for the bundle
            $table->text('description')->nullable();
            $table->date('active_from')->nullable();
            $table->date('active_to')->nullable();
            $table->timestamps();

            $table->foreign('parent_product_id')
                ->references('id')
                ->on('products')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_bundles');
    }
};
