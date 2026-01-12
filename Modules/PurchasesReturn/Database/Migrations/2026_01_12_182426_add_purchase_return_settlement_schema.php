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
        Schema::create('purchase_return_settlements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('purchase_return_id');
            $table->string('method'); // cash, deposit, exchange
            $table->string('status')->default('draft'); // draft, pending, approved, executing, completed, rejected
            
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            
            $table->string('cash_proof_path')->nullable();
            
            $table->timestamps();
            
            $table->foreign('purchase_return_id')->references('id')->on('purchase_returns')->onDelete('cascade');
        });

        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->timestamp('return_dispatched_at')->nullable()->after('settled_at');
            $table->unsignedBigInteger('return_dispatched_by')->nullable()->after('return_dispatched_at');
            $table->string('return_dispatch_status')->nullable()->after('return_dispatched_by'); // pending, dispatched
        });

        Schema::table('purchase_return_goods', function (Blueprint $table) {
            $table->unsignedBigInteger('received_by')->nullable()->after('quantity');
            $table->integer('received_quantity')->default(0)->after('received_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_return_goods', function (Blueprint $table) {
            $table->dropColumn(['received_by', 'received_quantity']);
        });

        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->dropColumn(['return_dispatched_at', 'return_dispatched_by', 'return_dispatch_status']);
        });

        Schema::dropIfExists('purchase_return_settlements');
    }
};
