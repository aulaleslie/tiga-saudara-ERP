<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('payment_terms', 'setting_id')) {
            Schema::table('payment_terms', function (Blueprint $table) {
                $table->dropForeign(['setting_id']);
                $table->dropColumn('setting_id');
            });
        }

        if (Schema::hasColumn('payment_methods', 'setting_id')) {
            Schema::table('payment_methods', function (Blueprint $table) {
                $table->dropColumn('setting_id');
            });
        }

        if (Schema::hasColumn('taxes', 'setting_id')) {
            Schema::table('taxes', function (Blueprint $table) {
                $table->dropForeign(['setting_id']);
                $table->dropColumn('setting_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('payment_terms', 'setting_id')) {
            Schema::table('payment_terms', function (Blueprint $table) {
                $table->unsignedBigInteger('setting_id')->after('id');
                $table->foreign('setting_id')->references('id')->on('settings')->onDelete('cascade');
            });
        }

        if (! Schema::hasColumn('payment_methods', 'setting_id')) {
            Schema::table('payment_methods', function (Blueprint $table) {
                $table->unsignedBigInteger('setting_id')->after('coa_id');
            });
        }

        if (! Schema::hasColumn('taxes', 'setting_id')) {
            Schema::table('taxes', function (Blueprint $table) {
                $table->unsignedBigInteger('setting_id')->after('id');
                $table->foreign('setting_id')->references('id')->on('settings')->onDelete('cascade');
            });
        }
    }
};
