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
        Schema::table('sale_returns', function (Blueprint $table) {
            $table->unsignedBigInteger('pos_return_id')->nullable()->index();
            $table->unsignedBigInteger('pos_transaction_id')->nullable()->index();
            $table->unsignedBigInteger('pos_checkout_id')->nullable()->index();
            $table->string('pos_return_option')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('sale_returns', function (Blueprint $table) {
            $table->dropColumn(['pos_return_id', 'pos_transaction_id', 'pos_checkout_id', 'pos_return_option']);
        });
    }
};
