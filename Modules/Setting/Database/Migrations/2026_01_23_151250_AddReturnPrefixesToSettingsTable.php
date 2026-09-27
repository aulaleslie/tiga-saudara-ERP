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
        Schema::table('settings', function (Blueprint $table) {
            $table->string('purchase_return_prefix_document')->nullable()->after('purchase_prefix_document');
            $table->string('sale_return_prefix_document')->nullable()->after('sale_prefix_document');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('purchase_return_prefix_document');
            $table->dropColumn('sale_return_prefix_document');
        });
    }
};
