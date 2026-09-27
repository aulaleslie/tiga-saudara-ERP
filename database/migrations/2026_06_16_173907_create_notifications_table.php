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
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('setting_id');
            $table->unsignedBigInteger('location_id')->nullable();
            
            $table->string('category');
            $table->string('type');
            $table->string('title');
            $table->text('message');
            
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            
            $table->string('fingerprint');
            $table->string('active_fingerprint')->virtualAs('CASE WHEN resolved_at IS NULL THEN fingerprint ELSE NULL END')->unique();
            $table->text('action_url')->nullable();
            $table->json('metadata')->nullable();
            
            $table->timestamp('read_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('setting_id');
            $table->index('location_id');
            $table->index('category');
            $table->index(['source_type', 'source_id']);
            $table->index('fingerprint');
            $table->index('read_at');
            $table->index('resolved_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
