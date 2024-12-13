<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->date('due_date')->after('date');
            $table->unsignedBigInteger('tax_id')->nullable()->after('supplier_id');

            $table->foreign('tax_id')->references('id')->on('taxes')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropForeign(['tax_id']);
            $table->dropColumn('tax_id');
            $table->dropColumn('due_date');
        });
    }
};
