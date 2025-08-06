<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
            // Place these after your existing document_prefix column
            $table->string('purchase_prefix_document')
                ->nullable()
                ->after('document_prefix');

            $table->string('sale_prefix_document')
                ->nullable()
                ->after('purchase_prefix_document');
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
            $table->dropColumn([
                'sale_prefix_document',
                'purchase_prefix_document',
            ]);
        });
    }
};
