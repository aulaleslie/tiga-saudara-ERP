<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedInteger('pos_idle_threshold_minutes')->default(30)->after('sale_prefix_document');
            $table->decimal('pos_default_cash_threshold', 15, 2)->default(0)->after('pos_idle_threshold_minutes');
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->decimal('pos_cash_threshold', 15, 2)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('pos_cash_threshold');
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['pos_idle_threshold_minutes', 'pos_default_cash_threshold']);
        });
    }
};
