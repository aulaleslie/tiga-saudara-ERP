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
        Schema::table('sale_return_details', function (Blueprint $table) {
            $table->unsignedBigInteger('pos_return_line_id')->nullable()->index();
            $table->string('bundle_group_key')->nullable()->index();
            $table->string('stock_behavior')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('sale_return_details', function (Blueprint $table) {
            $table->dropColumn(['pos_return_line_id', 'bundle_group_key', 'stock_behavior']);
        });
    }
};
