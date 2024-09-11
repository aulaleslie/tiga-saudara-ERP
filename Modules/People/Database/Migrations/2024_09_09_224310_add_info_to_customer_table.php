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
            // Adding new fields to the customers table
            $table->string('company_name')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('npwp')->nullable();
            $table->text('billing_address')->nullable();
            $table->text('shipping_address')->nullable();
            $table->string('fax')->nullable();
            $table->string('identity')->nullable();
            $table->string('identity_number')->nullable();

            // Bank information
            $table->string('bank_name')->nullable();
            $table->string('bank_branch')->nullable();
            $table->string('account_number')->nullable();
            $table->string('account_holder')->nullable();

            // Foreign key relation for setting_id
            $table->unsignedBigInteger('setting_id')->nullable();
            $table->foreign('setting_id')->references('id')->on('settings')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Dropping the added columns and foreign key
            $table->dropColumn([
                'company_name',
                'contact_name',
                'npwp',
                'billing_address',
                'shipping_address',
                'fax',
                'identity',
                'identity_number',
                'bank_name',
                'bank_branch',
                'account_number',
                'account_holder'
            ]);
            $table->dropForeign(['setting_id']);
            $table->dropColumn('setting_id');
        });
    }
};
