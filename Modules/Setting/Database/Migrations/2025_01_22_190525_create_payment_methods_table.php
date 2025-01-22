<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Payment method name
            $table->unsignedBigInteger('coa_id'); // Chart of Accounts ID
            $table->unsignedBigInteger('setting_id'); // Auto-filled from session
            $table->timestamps();

            // Foreign key constraints
            $table->foreign('coa_id')->references('id')->on('chart_of_accounts')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('payment_methods');
    }
};
