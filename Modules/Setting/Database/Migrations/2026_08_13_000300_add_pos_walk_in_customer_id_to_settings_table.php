<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('settings', 'pos_walk_in_customer_id')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedBigInteger('pos_walk_in_customer_id')
                ->nullable()
                ->after('pos_enabled');

            $table->foreign('pos_walk_in_customer_id')
                ->references('id')
                ->on('customers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('settings', 'pos_walk_in_customer_id')) {
            return;
        }

        $isSqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::table('settings', function (Blueprint $table) use ($isSqlite) {
            if (! $isSqlite) {
                try {
                    $table->dropForeign(['pos_walk_in_customer_id']);
                } catch (\Throwable) {
                    // Ignore if foreign key is already missing.
                }
            }

            $table->dropColumn('pos_walk_in_customer_id');
        });
    }
};
