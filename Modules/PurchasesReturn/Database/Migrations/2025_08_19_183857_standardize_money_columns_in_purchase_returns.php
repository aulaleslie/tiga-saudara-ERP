<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // purchase_returns
        DB::statement("ALTER TABLE purchase_returns
            MODIFY tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            MODIFY discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            MODIFY shipping_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            MODIFY total_amount DECIMAL(15,2) NOT NULL,
            MODIFY paid_amount DECIMAL(15,2) NOT NULL,
            MODIFY due_amount DECIMAL(15,2) NOT NULL");

        // purchase_return_details
        DB::statement("ALTER TABLE purchase_return_details
            MODIFY price DECIMAL(15,2) NOT NULL,
            MODIFY unit_price DECIMAL(15,2) NOT NULL,
            MODIFY sub_total DECIMAL(15,2) NOT NULL,
            MODIFY product_discount_amount DECIMAL(15,2) NOT NULL,
            MODIFY product_tax_amount DECIMAL(15,2) NOT NULL");

        // purchase_return_payments
        DB::statement("ALTER TABLE purchase_return_payments
            MODIFY amount DECIMAL(15,2) NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // rollback to int (not ideal if values > INT range were saved)
        DB::statement("ALTER TABLE purchase_returns
            MODIFY tax_amount INT NOT NULL DEFAULT 0,
            MODIFY discount_amount INT NOT NULL DEFAULT 0,
            MODIFY shipping_amount INT NOT NULL DEFAULT 0,
            MODIFY total_amount INT NOT NULL,
            MODIFY paid_amount INT NOT NULL,
            MODIFY due_amount INT NOT NULL");

        DB::statement("ALTER TABLE purchase_return_details
            MODIFY price INT NOT NULL,
            MODIFY unit_price INT NOT NULL,
            MODIFY sub_total INT NOT NULL,
            MODIFY product_discount_amount INT NOT NULL,
            MODIFY product_tax_amount INT NOT NULL");

        DB::statement("ALTER TABLE purchase_return_payments
            MODIFY amount INT NOT NULL");
    }
};
