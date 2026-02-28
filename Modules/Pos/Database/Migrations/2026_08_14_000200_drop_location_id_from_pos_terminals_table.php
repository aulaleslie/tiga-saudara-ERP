<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('pos_terminals', function (Blueprint $table) {
            if (Schema::hasColumn('pos_terminals', 'location_id')) {
                $table->dropForeign(['location_id']);
                $table->dropIndex(['location_id']);
                $table->dropColumn('location_id');
            }
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('pos_terminals', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_terminals', 'location_id')) {
                $table->unsignedBigInteger('location_id')->nullable()->after('name');
                $table->index('location_id');
                $table->foreign('location_id')->references('id')->on('locations')->onDelete('restrict');
            }
        });
    }
};
