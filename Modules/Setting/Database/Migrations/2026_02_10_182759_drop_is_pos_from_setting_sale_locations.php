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
        Schema::table('setting_sale_locations', function (Blueprint $table) {
            $table->dropColumn('is_pos');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('setting_sale_locations', function (Blueprint $table) {
            $table->boolean('is_pos')->default(false)->after('location_id');
        });
    }
};
