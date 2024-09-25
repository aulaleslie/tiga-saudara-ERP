<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddFieldsToSalesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->date('due_date')->nullable(); // Added field
            $table->string('customer_email')->nullable(); // Added field
            $table->string('paying_bill_address')->nullable(); // Added field
            $table->string('term_of_payment')->nullable(); // Added field
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['due_date', 'customer_email', 'paying_bill_address', 'term_of_payment']); // Remove added fields
        });
    }
}