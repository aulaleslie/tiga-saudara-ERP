<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_terminal_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('terminal_id')->unique();
            $table->boolean('require_session_open')->default(true);
            $table->boolean('require_opening_float')->default(true);
            $table->boolean('allow_total_only_float_input')->default(true);
            $table->decimal('close_variance_approval_threshold', 15, 2)->default(0);
            $table->decimal('cash_threshold', 15, 2)->nullable();
            $table->boolean('auto_open_drawer_on_session_open')->default(false);
            $table->boolean('auto_open_drawer_on_cash_sale')->default(false);
            $table->boolean('auto_open_drawer_on_pickup')->default(false);
            $table->boolean('auto_open_drawer_on_close')->default(false);
            $table->boolean('require_pickup_supervisor_approval')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('terminal_id')->references('id')->on('pos_terminals')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_terminal_policies');
    }
};
