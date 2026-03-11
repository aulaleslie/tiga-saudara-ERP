<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_checkouts') || Schema::hasColumn('pos_checkouts', 'split_summary')) {
            return;
        }

        Schema::table('pos_checkouts', function (Blueprint $table) {
            $table->json('split_summary')->nullable()->after('response_payload');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_checkouts') || ! Schema::hasColumn('pos_checkouts', 'split_summary')) {
            return;
        }

        Schema::table('pos_checkouts', function (Blueprint $table) {
            $table->dropColumn('split_summary');
        });
    }
};
