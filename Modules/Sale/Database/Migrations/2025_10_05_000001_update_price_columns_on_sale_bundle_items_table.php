<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_bundle_items', function (Blueprint $table) {
            $table->decimal('price', 15, 2)->change();
            $table->decimal('sub_total', 15, 2)->change();
        });
    }

    public function down(): void
    {
        Schema::table('sale_bundle_items', function (Blueprint $table) {
            $table->integer('price')->change();
            $table->integer('sub_total')->change();
        });
    }
};
