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
        Schema::table('customers', function (Blueprint $table) {
            $table->string('customer_email')->nullable()->change();
            $table->string('customer_phone')->nullable()->change();
            $table->string('address')->nullable()->change();
            $table->string('city')->nullable()->change();
            $table->string('country')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('customer_email')->nullable(false)->change();
            $table->string('customer_phone')->nullable(false)->change();
            $table->string('address')->nullable(false)->change();
            $table->string('city')->nullable(false)->change();
            $table->string('country')->nullable(false)->change();
        });
    }
};
