<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('expense_import_batches')->onDelete('cascade');
            $table->integer('row_number');
            $table->json('raw_json');
            $table->string('status')->default('pending'); // pending, valid, invalid, processed, skipped
            $table->text('error_message')->nullable();
            $table->foreignId('expense_id')->nullable()->constrained()->onDelete('set null');
            $table->timestamps();

            $table->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_import_rows');
    }
};
