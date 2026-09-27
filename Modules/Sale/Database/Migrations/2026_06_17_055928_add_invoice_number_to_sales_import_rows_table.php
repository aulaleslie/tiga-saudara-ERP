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
        Schema::table('sales_import_rows', function (Blueprint $table) {
            $table->string('invoice_number')->nullable()->after('batch_id');
            $table->index(['batch_id', 'status', 'invoice_number'], 'sales_import_rows_batch_status_invoice_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('sales_import_rows', function (Blueprint $table) {
            $table->dropIndex('sales_import_rows_batch_status_invoice_index');
            $table->dropColumn('invoice_number');
        });
    }
};
