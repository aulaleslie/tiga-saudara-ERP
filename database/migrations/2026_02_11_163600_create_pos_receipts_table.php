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
        Schema::create('pos_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number')->unique();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('due_amount', 15, 2)->default(0);
            $table->decimal('change_due', 15, 2)->default(0);
            $table->string('payment_status')->default('Unpaid');
            $table->string('payment_method')->nullable();
            $table->json('payment_breakdown')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('pos_session_id')->nullable()->constrained('pos_sessions')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('pos_receipt_id')->nullable()->after('pos_session_id')->constrained('pos_receipts')->nullOnDelete();
        });

        Schema::table('sale_payments', function (Blueprint $table) {
            $table->foreignId('pos_receipt_id')->nullable()->after('pos_session_id')->constrained('pos_receipts')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropForeign(['pos_receipt_id']);
            $table->dropColumn('pos_receipt_id');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['pos_receipt_id']);
            $table->dropColumn('pos_receipt_id');
        });

        Schema::dropIfExists('pos_receipts');
    }
};
