<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            // Add the setting_id column
            $table->unsignedBigInteger('setting_id')->after('id');

            // Add a foreign key constraint to the settings table (if applicable)
            $table->foreign('setting_id')->references('id')->on('settings')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            // Drop the foreign key and the column
            $table->dropForeign(['setting_id']);
            $table->dropColumn('setting_id');
        });
    }
};
