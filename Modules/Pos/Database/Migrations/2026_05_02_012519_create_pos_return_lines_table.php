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
        Schema::create('pos_return_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pos_return_id')->index();
            $table->unsignedBigInteger('pos_checkout_sale_id')->index();
            $table->unsignedBigInteger('sale_return_id')->nullable()->index();
            $table->unsignedBigInteger('sale_return_detail_id')->nullable()->index();
            $table->unsignedBigInteger('sale_id')->index();
            $table->unsignedBigInteger('sale_detail_id')->index();
            $table->unsignedBigInteger('dispatch_detail_id')->nullable()->index();
            $table->unsignedBigInteger('source_setting_id')->index();
            $table->unsignedBigInteger('source_location_id')->index();
            $table->unsignedBigInteger('tax_id')->nullable()->index();
            $table->unsignedBigInteger('product_id')->index();
            $table->string('product_name');
            $table->string('product_code');
            $table->decimal('quantity', 16, 4);
            $table->decimal('unit_price', 16, 2);
            $table->decimal('line_total', 16, 2);
            $table->json('serial_number_ids')->nullable();
            $table->string('bundle_group_key')->nullable()->index();
            $table->unsignedBigInteger('bundle_parent_sale_detail_id')->nullable()->index();
            $table->decimal('bundle_quantity', 16, 4)->nullable();
            $table->decimal('component_quantity_per_bundle', 16, 4)->nullable();
            $table->string('stock_behavior')->index(); // stock_managed | stockless
            $table->unsignedBigInteger('replacement_product_id')->nullable()->index();
            $table->decimal('replacement_quantity', 16, 4)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('pos_return_lines');
    }
};
