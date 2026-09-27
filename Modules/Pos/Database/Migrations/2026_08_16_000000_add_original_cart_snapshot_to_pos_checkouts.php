<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    // Run after 2026_08_15_000400 (the last existing pos_checkouts modification)
    public function up()
    {
        Schema::table('pos_checkouts', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_checkouts', 'original_cart_snapshot')) {
                $table->json('original_cart_snapshot')->nullable()->after('payload_hash');
            }
        });
    }

    public function down()
    {
        Schema::table('pos_checkouts', function (Blueprint $table) {
            $table->dropColumn('original_cart_snapshot');
        });
    }
};
