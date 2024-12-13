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
        Schema::table('purchase_details', function (Blueprint $table) {
            $table->unsignedBigInteger('tax_id')->nullable()->after('product_tax_amount');

            // Add foreign key constraint
            $table->foreign('tax_id')->references('id')->on('taxes')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('purchase_details', function (Blueprint $table) {
            $table->dropForeign(['tax_id']);
            $table->dropColumn('tax_id');
        });
    }
};
