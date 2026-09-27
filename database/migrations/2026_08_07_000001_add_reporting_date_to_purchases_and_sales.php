<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dateTime('reporting_date')->nullable()->index();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dateTime('reporting_date')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropIndex(['reporting_date']);
            $table->dropColumn('reporting_date');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['reporting_date']);
            $table->dropColumn('reporting_date');
        });
    }
};
