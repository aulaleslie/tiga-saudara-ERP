<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_status_transition_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('setting_id');
            $table->unsignedBigInteger('purchase_id');
            $table->unsignedBigInteger('received_note_id')->nullable();
            $table->string('old_status')->nullable();
            $table->string('new_status');
            $table->string('action_or_source');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('transitioned_at')->useCurrent();
            $table->timestamps();

            $table->foreign('setting_id')->references('id')->on('settings')->onDelete('restrict');
            $table->foreign('purchase_id')->references('id')->on('purchases')->onDelete('cascade');
            $table->foreign('received_note_id')->references('id')->on('received_notes')->onDelete('set null');
            $table->foreign('actor_user_id')->references('id')->on('users')->onDelete('set null');

            $table->index('setting_id');
            $table->index('purchase_id');
            $table->index('received_note_id');
            $table->index('actor_user_id');
            $table->index('transitioned_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_status_transition_audits');
    }
};
