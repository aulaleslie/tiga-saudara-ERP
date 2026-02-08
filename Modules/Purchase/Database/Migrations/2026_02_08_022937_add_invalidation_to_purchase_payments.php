<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddInvalidationToPurchasePayments extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('purchase_payments', function (Blueprint $table) {
            $table->string('status')->default('ACTIVE')->after('note');
            $table->timestamp('invalidated_at')->nullable()->after('status');
            $table->foreignId('invalidated_by')->nullable()->constrained('users')->nullOnDelete()->after('invalidated_at');
            $table->string('invalidation_source')->nullable()->after('invalidated_by');
            $table->unsignedBigInteger('invalidation_source_id')->nullable()->after('invalidation_source');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('purchase_payments', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropForeign(['invalidated_by']);
            $table->dropColumn(['status', 'invalidated_at', 'invalidated_by', 'invalidation_source', 'invalidation_source_id']);
        });
    }
}
