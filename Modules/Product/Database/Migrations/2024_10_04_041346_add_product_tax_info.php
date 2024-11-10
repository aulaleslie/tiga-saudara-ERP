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
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('purchase_tax_id')->nullable()->constrained('taxes')->onDelete('set null');
            $table->foreignId('sale_tax_id')->nullable()->constrained('taxes')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('purchase_tax_id');
            $table->dropColumn('sale_tax_id');
        });
    }
};
