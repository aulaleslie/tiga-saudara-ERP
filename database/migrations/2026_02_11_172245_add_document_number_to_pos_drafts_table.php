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
        Schema::table('pos_drafts', function (Blueprint $table) {
            $table->string('document_number')
                ->after('user_id')
                ->index();
                
            // Ensure uniqueness per setting to fulfill "unique per setting per month"
            // We use a composite index for lookup speed, but uniqueness is logically on Setting+Month+Number
            // However, the number format itself "PREFIX-YYYY-MM-XXXXX" typically includes month/year-like structure,
            // so a simple unique constraint on (setting_id, document_number) is robust.
            $table->unique(['setting_id', 'document_number'], 'pos_drafts_setting_doc_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pos_drafts', function (Blueprint $table) {
            $table->dropUnique('pos_drafts_setting_doc_unique');
            $table->dropColumn('document_number');
        });
    }
};
