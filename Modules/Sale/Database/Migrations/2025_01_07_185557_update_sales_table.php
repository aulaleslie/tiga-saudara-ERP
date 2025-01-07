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
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_term_id')->nullable()->after('customer_id');
            $table->unsignedBigInteger('tax_id')->nullable()->after('payment_term_id');
            $table->unsignedBigInteger('setting_id')->nullable()->after('tax_id');

            $table->foreign('payment_term_id')->references('id')->on('payment_terms')->nullOnDelete();
            $table->foreign('tax_id')->references('id')->on('taxes')->nullOnDelete();
            $table->foreign('setting_id')->references('id')->on('settings')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['payment_term_id']);
            $table->dropForeign(['tax_id']);
            $table->dropForeign(['setting_id']);

            $table->dropColumn('payment_term_id');
            $table->dropColumn('tax_id');
            $table->dropColumn('setting_id');
        });
    }
};
