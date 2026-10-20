<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('pos_kas_bon_collection_methods')) {
            Schema::table('pos_kas_bon_collection_methods', function (Blueprint $table) {
                if (!Schema::hasColumn('pos_kas_bon_collection_methods', 'reference')) {
                    $table->string('reference', 191)->nullable()->after('sequence_order');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('pos_kas_bon_collection_methods')) {
            Schema::table('pos_kas_bon_collection_methods', function (Blueprint $table) {
                if (Schema::hasColumn('pos_kas_bon_collection_methods', 'reference')) {
                    $table->dropColumn('reference');
                }
            });
        }
    }
};
