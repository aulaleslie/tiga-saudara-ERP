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
        if (!Schema::hasColumn('pos_transactions', 'note')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                $table->string('note', 1000)->nullable();
            });
        }

        if (!Schema::hasColumn('pos_checkouts', 'note')) {
            Schema::table('pos_checkouts', function (Blueprint $table) {
                $table->string('note', 1000)->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('pos_transactions', 'note')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                $table->dropColumn('note');
            });
        }

        if (Schema::hasColumn('pos_checkouts', 'note')) {
            Schema::table('pos_checkouts', function (Blueprint $table) {
                $table->dropColumn('note');
            });
        }
    }
};
