<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('transfers', 'workflow_version')) {
            Schema::table('transfers', function (Blueprint $table) {
                $table->unsignedSmallInteger('workflow_version')->default(1)->after('revision');
            });
        }

        // Ensure all existing records explicitly have workflow_version = 1
        DB::table('transfers')
            ->whereNull('workflow_version')
            ->update(['workflow_version' => 1]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('transfers', 'workflow_version')) {
            Schema::table('transfers', function (Blueprint $table) {
                $table->dropColumn('workflow_version');
            });
        }
    }
};
