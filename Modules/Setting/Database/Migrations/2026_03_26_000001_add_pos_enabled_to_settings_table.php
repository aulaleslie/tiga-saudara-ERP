<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('settings', 'pos_enabled')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('pos_enabled')
                ->default(false)
                ->after('sale_prefix_document');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('settings', 'pos_enabled')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('pos_enabled');
        });
    }
};
