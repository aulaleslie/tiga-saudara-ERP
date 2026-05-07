<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('pos_return_lines', function (Blueprint $table) {
            $table->string('resolution')->nullable()->index()->after('pos_checkout_sale_id');
            $table->unsignedBigInteger('pos_transaction_line_id')->nullable()->index()->after('dispatch_detail_id');
            $table->unsignedBigInteger('returned_serial_id')->nullable()->index()->after('serial_number_ids');
            $table->unsignedBigInteger('replacement_serial_id')->nullable()->index()->after('returned_serial_id');
            $table->decimal('expected_cash_amount', 16, 2)->nullable()->after('line_total');
            $table->json('line_meta')->nullable()->after('replacement_quantity');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('pos_return_lines', function (Blueprint $table) {
            $table->dropColumn([
                'resolution',
                'pos_transaction_line_id',
                'returned_serial_id',
                'replacement_serial_id',
                'expected_cash_amount',
                'line_meta',
            ]);
        });
    }
};
