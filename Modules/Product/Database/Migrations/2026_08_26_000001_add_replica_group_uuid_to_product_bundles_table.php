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
        Schema::table('product_bundles', function (Blueprint $table) {
            $table->uuid('replica_group_uuid')->nullable()->after('setting_id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_bundles', function (Blueprint $table) {
            $table->dropIndex(['replica_group_uuid']);
            $table->dropColumn('replica_group_uuid');
        });
    }
};
