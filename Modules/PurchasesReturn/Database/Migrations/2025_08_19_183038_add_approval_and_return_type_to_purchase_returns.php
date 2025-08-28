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
            // workflow status (document/approval)
            $table->string('approval_status')->default('draft')->after('due_amount'); // draft|pending|approved|rejected

            // what supplier gives back
            $table->string('return_type')->nullable()->after('approval_status');     // cash|deposit|exchange

            // audit fields
            $table->foreignId('approved_by')->nullable()->after('return_type')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('rejected_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->text('rejection_reason')->nullable()->after('rejected_at');

            // helpful indexes
            $table->index('approval_status');
            $table->index('return_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->dropIndex(['approval_status']);
            $table->dropIndex(['return_type']);

            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');

            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn(['rejected_at', 'rejection_reason']);

            $table->dropColumn(['approval_status', 'return_type']);
        });
    }
};
