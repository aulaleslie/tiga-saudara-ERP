<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_drafts', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_drafts', 'locked_until')) {
                $table->timestamp('locked_until')->nullable()->after('locked_at')->index();
            }

            if (! Schema::hasColumn('pos_drafts', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('locked_until');
            }

            if (! Schema::hasColumn('pos_drafts', 'last_touched_at')) {
                $table->timestamp('last_touched_at')->nullable()->after('submitted_at')->index();
            }

            $table->index(['setting_id', 'status', 'expires_at'], 'pos_drafts_setting_status_exp_idx');
            $table->index(['setting_id', 'document_number'], 'pos_drafts_setting_number_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pos_drafts', function (Blueprint $table) {
            if (Schema::hasColumn('pos_drafts', 'last_touched_at')) {
                $table->dropColumn('last_touched_at');
            }

            if (Schema::hasColumn('pos_drafts', 'submitted_at')) {
                $table->dropColumn('submitted_at');
            }

            if (Schema::hasColumn('pos_drafts', 'locked_until')) {
                $table->dropColumn('locked_until');
            }

            $table->dropIndex('pos_drafts_setting_status_exp_idx');
            $table->dropIndex('pos_drafts_setting_number_idx');
        });
    }
};
