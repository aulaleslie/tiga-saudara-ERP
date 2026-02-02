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
        Schema::table('sale_return_item_settlements', function (Blueprint $table) {
            $table->string('dispatched_serial_number')->nullable()->after('rejection_reason');
            $table->timestamp('dispatch_requested_at')->nullable()->after('dispatched_serial_number');
            $table->unsignedBigInteger('dispatch_requested_by')->nullable()->after('dispatch_requested_at');
            $table->timestamp('dispatch_approved_at')->nullable()->after('dispatch_requested_by');
            $table->unsignedBigInteger('dispatch_approved_by')->nullable()->after('dispatch_approved_at');
            $table->timestamp('dispatch_rejected_at')->nullable()->after('dispatch_approved_by');
            $table->unsignedBigInteger('dispatch_rejected_by')->nullable()->after('dispatch_rejected_at');
            $table->text('dispatch_rejection_reason')->nullable()->after('dispatch_rejected_by');

            $table->foreign('dispatch_requested_by', 'sr_items_settlement_disp_req_by_foreign')->references('id')->on('users')->onDelete('set null');
            $table->foreign('dispatch_approved_by', 'sr_items_settlement_disp_app_by_foreign')->references('id')->on('users')->onDelete('set null');
            $table->foreign('dispatch_rejected_by', 'sr_items_settlement_disp_rej_by_foreign')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_return_item_settlements', function (Blueprint $table) {
            $table->dropForeign('sr_items_settlement_disp_req_by_foreign');
            $table->dropForeign('sr_items_settlement_disp_app_by_foreign');
            $table->dropForeign('sr_items_settlement_disp_rej_by_foreign');

            $table->dropColumn([
                'dispatched_serial_number',
                'dispatch_requested_at',
                'dispatch_requested_by',
                'dispatch_approved_at',
                'dispatch_approved_by',
                'dispatch_rejected_at',
                'dispatch_rejected_by',
                'dispatch_rejection_reason',
            ]);
        });
    }
};
