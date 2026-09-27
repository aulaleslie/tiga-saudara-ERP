<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reporting_date_audits', function (Blueprint $table) {
            $table->id();
            $table->morphs('auditable'); // polymorphic: auditable_type, auditable_id
            $table->bigInteger('setting_id');
            $table->bigInteger('user_id')->nullable();
            $table->string('reason');
            $table->dateTime('original_date');
            $table->dateTime('prior_override')->nullable();
            $table->dateTime('resulting_override')->nullable();
            $table->timestamps();

            // Indexes for efficient lookups
            $table->index(['setting_id']);
            $table->index(['user_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reporting_date_audits');
    }
};
