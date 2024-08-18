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
        Schema::table('products', function (Blueprint $table) {
            // Drop the existing foreign key constraints
            $table->dropForeign(['category_id']);
            $table->dropForeign(['brand_id']);
            $table->dropForeign(['base_unit_id']);

            // Make the columns nullable
            $table->unsignedBigInteger('category_id')->nullable()->change();
            $table->unsignedInteger('brand_id')->nullable()->change();
            $table->unsignedBigInteger('base_unit_id')->nullable()->change();

            // Re-add the foreign key constraints with ON DELETE SET NULL
            $table->foreign('category_id')->references('id')->on('categories')->onDelete('set null');
            $table->foreign('brand_id')->references('id')->on('brands')->onDelete('set null');
            $table->foreign('base_unit_id')->references('id')->on('units')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Drop the modified foreign key constraints
            $table->dropForeign(['category_id']);
            $table->dropForeign(['brand_id']);
            $table->dropForeign(['base_unit_id']);


            // Revert the columns to not nullable
            $table->unsignedBigInteger('category_id')->nullable(false)->change();
            $table->unsignedInteger('brand_id')->nullable(false)->change();
            $table->unsignedBigInteger('base_unit_id')->nullable(false)->change();

            // Re-add the original foreign key constraints
            $table->foreign('category_id')->references('id')->on('categories');
            $table->foreign('brand_id')->references('id')->on('brands');
            $table->foreign('base_unit_id')->references('id')->on('units');
        });
    }
};
