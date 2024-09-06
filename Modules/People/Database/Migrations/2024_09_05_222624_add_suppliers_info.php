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
        Schema::table('suppliers', function (Blueprint $table) {
            // Additional fields based on the form adjustments
            $table->string('contact_name')->nullable();
            $table->string('identity')->nullable();
            $table->string('identity_number')->nullable();
            $table->string('fax')->nullable();
            $table->string('npwp')->nullable();
            $table->text('billing_address')->nullable();
            $table->text('shipping_address')->nullable();

            // Bank information
            $table->string('bank_name')->nullable();
            $table->string('bank_branch')->nullable();
            $table->string('account_number')->nullable();
            $table->string('account_holder')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            // Dropping the added columns
            $table->dropColumn([
                'contact_name',
                'identity',
                'identity_number',
                'fax',
                'npwp',
                'billing_address',
                'shipping_address',
                'bank_name',
                'bank_branch',
                'account_number',
                'account_holder'
            ]);
        });
    }
};
