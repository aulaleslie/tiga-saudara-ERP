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
        Schema::table('pos_return_lines', function (Blueprint $table) {
            $table->string('resolution')->nullable()->index()->after('pos_checkout_sale_id');
            $table->unsignedBigInteger('pos_transaction_line_id')->nullable()->index()->after('pos_checkout_sale_id');
            $table->unsignedBigInteger('returned_serial_id')->nullable()->index()->after('serial_number_ids');
            $table->unsignedBigInteger('replacement_serial_id')->nullable()->index()->after('replacement_quantity');
            $table->decimal('expected_cash_amount', 16, 2)->nullable()->after('line_total');
        });

        Schema::table('pos_returns', function (Blueprint $table) {
            $table->unsignedBigInteger('deleted_by')->nullable()->index()->after('updated_by');
            $table->text('delete_reason')->nullable()->after('deleted_by');
            $table->softDeletes()->after('delete_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pos_returns', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['deleted_by', 'delete_reason']);
        });

        Schema::table('pos_return_lines', function (Blueprint $table) {
            $table->dropColumn([
                'resolution',
                'pos_transaction_line_id',
                'returned_serial_id',
                'replacement_serial_id',
                'expected_cash_amount',
            ]);
        });
    }
};
