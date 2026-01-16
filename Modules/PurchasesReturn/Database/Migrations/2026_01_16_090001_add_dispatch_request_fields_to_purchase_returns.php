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
        Schema::table('purchase_returns', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_returns', 'return_awb_number')) {
                $table->string('return_awb_number')->nullable()->after('return_dispatch_status');
            }

            if (! Schema::hasColumn('purchase_returns', 'return_shipping_amount')) {
                $table->decimal('return_shipping_amount', 15, 2)->default(0)->after('return_awb_number');
            }

            if (! Schema::hasColumn('purchase_returns', 'return_carrier')) {
                $table->string('return_carrier')->nullable()->after('return_shipping_amount');
            }

            if (! Schema::hasColumn('purchase_returns', 'return_dispatch_note')) {
                $table->text('return_dispatch_note')->nullable()->after('return_carrier');
            }

            if (! Schema::hasColumn('purchase_returns', 'dispatch_requested_by')) {
                $table->foreignId('dispatch_requested_by')
                    ->nullable()
                    ->after('return_dispatch_note')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('purchase_returns', 'dispatch_requested_at')) {
                $table->timestamp('dispatch_requested_at')->nullable()->after('dispatch_requested_by');
            }

            if (! Schema::hasColumn('purchase_returns', 'dispatch_approved_by')) {
                $table->foreignId('dispatch_approved_by')
                    ->nullable()
                    ->after('dispatch_requested_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('purchase_returns', 'dispatch_approved_at')) {
                $table->timestamp('dispatch_approved_at')->nullable()->after('dispatch_approved_by');
            }

            if (! Schema::hasColumn('purchase_returns', 'dispatch_rejected_by')) {
                $table->foreignId('dispatch_rejected_by')
                    ->nullable()
                    ->after('dispatch_approved_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('purchase_returns', 'dispatch_rejected_at')) {
                $table->timestamp('dispatch_rejected_at')->nullable()->after('dispatch_rejected_by');
            }

            if (! Schema::hasColumn('purchase_returns', 'dispatch_rejection_reason')) {
                $table->text('dispatch_rejection_reason')->nullable()->after('dispatch_rejected_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_returns', 'dispatch_rejection_reason')) {
                $table->dropColumn('dispatch_rejection_reason');
            }

            if (Schema::hasColumn('purchase_returns', 'dispatch_rejected_at')) {
                $table->dropColumn('dispatch_rejected_at');
            }

            if (Schema::hasColumn('purchase_returns', 'dispatch_rejected_by')) {
                $table->dropConstrainedForeignId('dispatch_rejected_by');
            }

            if (Schema::hasColumn('purchase_returns', 'dispatch_approved_at')) {
                $table->dropColumn('dispatch_approved_at');
            }

            if (Schema::hasColumn('purchase_returns', 'dispatch_approved_by')) {
                $table->dropConstrainedForeignId('dispatch_approved_by');
            }

            if (Schema::hasColumn('purchase_returns', 'dispatch_requested_at')) {
                $table->dropColumn('dispatch_requested_at');
            }

            if (Schema::hasColumn('purchase_returns', 'dispatch_requested_by')) {
                $table->dropConstrainedForeignId('dispatch_requested_by');
            }

            if (Schema::hasColumn('purchase_returns', 'return_dispatch_note')) {
                $table->dropColumn('return_dispatch_note');
            }

            if (Schema::hasColumn('purchase_returns', 'return_carrier')) {
                $table->dropColumn('return_carrier');
            }

            if (Schema::hasColumn('purchase_returns', 'return_shipping_amount')) {
                $table->dropColumn('return_shipping_amount');
            }

            if (Schema::hasColumn('purchase_returns', 'return_awb_number')) {
                $table->dropColumn('return_awb_number');
            }
        });
    }
};
