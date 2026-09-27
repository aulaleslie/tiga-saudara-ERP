<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('product_merge_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('survivor_product_id');
            $table->string('canonical_key');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('survivor_product_id')->references('id')->on('products')->onDelete('restrict');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });

        Schema::create('product_merge_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merge_event_id');
            $table->unsignedBigInteger('retired_product_id')->unique();
            $table->json('migrated_relations_snapshot')->nullable();
            $table->json('actual_migrated_counts')->nullable();

            $table->foreign('merge_event_id')->references('id')->on('product_merge_events')->onDelete('restrict');
            $table->foreign('retired_product_id')->references('id')->on('products')->onDelete('restrict');
        });

        Schema::create('product_reference_migrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('audit_id');
            $table->string('table_name');
            $table->unsignedBigInteger('row_id');
            $table->unsignedBigInteger('old_product_id');
            $table->unsignedBigInteger('new_product_id');

            $table->foreign('audit_id')->references('id')->on('product_merge_audits')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_reference_migrations');
        Schema::dropIfExists('product_merge_audits');
        Schema::dropIfExists('product_merge_events');
    }
};
