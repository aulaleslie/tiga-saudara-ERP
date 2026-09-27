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
        if (! Schema::hasTable('pos_return_lines')) {
            return;
        }

        Schema::table('pos_return_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_return_lines', 'resolution')) {
                $table->string('resolution')->nullable()->index()->after('pos_checkout_sale_id');
            }

            if (! Schema::hasColumn('pos_return_lines', 'pos_transaction_line_id')) {
                $table->unsignedBigInteger('pos_transaction_line_id')->nullable()->index()->after('dispatch_detail_id');
            }

            if (! Schema::hasColumn('pos_return_lines', 'returned_serial_id')) {
                $table->unsignedBigInteger('returned_serial_id')->nullable()->index()->after('serial_number_ids');
            }

            if (! Schema::hasColumn('pos_return_lines', 'replacement_serial_id')) {
                $table->unsignedBigInteger('replacement_serial_id')->nullable()->index()->after('returned_serial_id');
            }

            if (! Schema::hasColumn('pos_return_lines', 'expected_cash_amount')) {
                $table->decimal('expected_cash_amount', 16, 2)->nullable()->after('line_total');
            }

            if (! Schema::hasColumn('pos_return_lines', 'line_meta')) {
                $table->json('line_meta')->nullable()->after('replacement_quantity');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (! Schema::hasTable('pos_return_lines')) {
            return;
        }

        Schema::table('pos_return_lines', function (Blueprint $table) {
            $columns = [];

            foreach ([
                'resolution',
                'pos_transaction_line_id',
                'returned_serial_id',
                'replacement_serial_id',
                'expected_cash_amount',
                'line_meta',
            ] as $column) {
                if (Schema::hasColumn('pos_return_lines', $column)) {
                    $columns[] = $column;
                }
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
