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
        Schema::create('product_import_batches', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Who started it (nullable if you have service accounts/cron)
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Location chosen at upload time (required by your flow)
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();

            // File bookkeeping
            $table->string('source_csv_path', 1024);
            $table->string('result_csv_path', 1024)->nullable(); // annotated CSV output
            $table->string('file_sha256', 64)->nullable();       // optional: dedupe/diagnostics

            // Status & progress
            $table->enum('status', ['queued','validating','processing','completed','failed','canceled'])
                ->default('queued');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('success_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);

            // Undo controls
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('undo_available_until')->nullable(); // now + 1 hour
            $table->timestamp('undone_at')->nullable();
            $table->string('undo_token', 64)->nullable()->unique(); // simple CSRF-ish guard

            $table->timestamps();

            // Helpful indexes
            $table->index(['status']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_import_batches');
    }
};
