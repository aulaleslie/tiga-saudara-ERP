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
        Schema::create('product_price_feed_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_uuid')->unique();
            $table->string('event_type', 50); // product_created, product_price_updated, bundle_created, bundle_price_updated
            $table->string('subject_type', 50); // product, bundle
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_name', 255);
            $table->string('subject_code', 100)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('actor_name', 255)->nullable();
            $table->string('source', 50)->default('Manual'); // Manual, QuickAdd, PurchaseSync, Import, System
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['occurred_at', 'id'], 'idx_feed_events_occurred_id');
            $table->index('subject_id');
            $table->index('user_id');
        });

        Schema::create('product_price_feed_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('product_price_feed_events')->onDelete('cascade');
            $table->unsignedBigInteger('setting_id');
            $table->string('setting_name', 255);
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->timestamps();

            $table->index(['setting_id', 'event_id'], 'idx_feed_snapshots_setting_event');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_price_feed_snapshots');
        Schema::dropIfExists('product_price_feed_events');
    }
};
