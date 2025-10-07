<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('movement_type', 50)->index();
            $table->decimal('cash_total', 15, 2)->default(0);
            $table->decimal('expected_total', 15, 2)->nullable();
            $table->decimal('variance', 15, 2)->nullable();
            $table->json('denominations')->nullable();
            $table->json('documents')->nullable();
            $table->json('metadata')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('recorded_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_cash_movements');
    }
};
