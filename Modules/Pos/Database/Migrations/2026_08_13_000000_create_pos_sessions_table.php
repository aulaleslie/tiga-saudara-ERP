<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('setting_id');
            $table->unsignedBigInteger('terminal_id');
            $table->unsignedBigInteger('cashier_user_id');
            $table->string('status', 20)->default('OPEN');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('opened_by')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->decimal('opening_float_total', 15, 2)->default(0);
            $table->decimal('expected_cash_total', 15, 2)->default(0);
            $table->decimal('counted_cash_total', 15, 2)->nullable();
            $table->decimal('variance_total', 15, 2)->nullable();
            $table->text('close_notes')->nullable();
            $table->unsignedBigInteger('close_approved_by')->nullable();
            $table->timestamp('close_approved_at')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedTinyInteger('active_marker')->nullable();
            $table->timestamps();

            $table->index(['setting_id', 'status']);
            $table->index(['terminal_id', 'status']);
            $table->index(['cashier_user_id', 'status']);
            $table->unique(
                ['setting_id', 'terminal_id', 'cashier_user_id', 'active_marker'],
                'pos_sessions_active_pair_unique'
            );

            $table->foreign('setting_id')->references('id')->on('settings')->onDelete('cascade');
            $table->foreign('terminal_id')->references('id')->on('pos_terminals')->onDelete('restrict');
            $table->foreign('cashier_user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('opened_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('closed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('close_approved_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sessions');
    }
};
