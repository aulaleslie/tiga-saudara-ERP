<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'pos_draft_flow_enabled')) {
                $table->boolean('pos_draft_flow_enabled')
                    ->default(false)
                    ->after('pos_document_prefix');
            }

            if (! Schema::hasColumn('settings', 'pos_draft_expiry_minutes')) {
                $table->unsignedInteger('pos_draft_expiry_minutes')
                    ->default(1440)
                    ->after('pos_draft_flow_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'pos_draft_expiry_minutes')) {
                $table->dropColumn('pos_draft_expiry_minutes');
            }

            if (Schema::hasColumn('settings', 'pos_draft_flow_enabled')) {
                $table->dropColumn('pos_draft_flow_enabled');
            }
        });
    }
};
