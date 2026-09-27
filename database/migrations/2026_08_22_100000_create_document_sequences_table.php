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
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 32);
            $table->unsignedBigInteger('setting_id');
            $table->string('prefix', 64);
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->foreign('setting_id')->references('id')->on('settings')->cascadeOnDelete();

            $table->unique(
                ['document_type', 'setting_id', 'prefix', 'period_year', 'period_month'],
                'doc_seq_type_setting_prefix_period_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
