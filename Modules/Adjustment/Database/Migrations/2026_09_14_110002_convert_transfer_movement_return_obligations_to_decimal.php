<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_movement_return_obligations', function (Blueprint $table) {
            $table->decimal('required_quantity', 14, 4)->default(0)->change();
            $table->decimal('returned_quantity', 14, 4)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('transfer_movement_return_obligations', function (Blueprint $table) {
            $table->unsignedInteger('required_quantity')->change();
            $table->unsignedInteger('returned_quantity')->default(0)->change();
        });
    }
};
