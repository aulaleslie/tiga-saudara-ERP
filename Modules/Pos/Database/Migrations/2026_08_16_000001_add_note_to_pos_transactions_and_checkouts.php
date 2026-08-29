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
        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->string('note', 1000)->nullable();
        });

        Schema::table('pos_checkouts', function (Blueprint $table) {
            $table->string('note', 1000)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->dropColumn('note');
        });

        Schema::table('pos_checkouts', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
