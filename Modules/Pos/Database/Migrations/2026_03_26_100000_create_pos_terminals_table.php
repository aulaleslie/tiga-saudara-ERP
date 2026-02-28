<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_terminals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('setting_id');
            $table->string('code', 50);
            $table->string('name', 100);
            $table->unsignedBigInteger('location_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['setting_id', 'code']);
            $table->index(['setting_id', 'is_active']);
            $table->index('location_id');

            $table->foreign('setting_id')->references('id')->on('settings')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_terminals');
    }
};
