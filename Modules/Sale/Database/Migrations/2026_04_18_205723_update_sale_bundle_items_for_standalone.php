<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sale_bundle_items', function (Blueprint $table) {
            $table->unsignedBigInteger('sale_detail_id')->nullable()->change();
            $table->unsignedBigInteger('tax_id')->nullable()->after('product_id');
            $table->integer('tax_amount')->default(0)->after('tax_id');
            $table->string('line_group_key')->nullable()->after('tax_amount');

            $table->foreign('tax_id')->references('id')->on('taxes')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_bundle_items', function (Blueprint $table) {
            $table->dropForeign(['tax_id']);
            $table->dropColumn(['tax_id', 'tax_amount', 'line_group_key']);
            $table->unsignedBigInteger('sale_detail_id')->nullable(false)->change();
        });
    }
};
