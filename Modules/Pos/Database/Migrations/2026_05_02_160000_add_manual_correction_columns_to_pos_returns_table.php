<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_returns', function (Blueprint $table) {
            $table->string('manual_correction_action')->nullable()->after('cancel_reason');
            $table->text('manual_correction_reason')->nullable()->after('manual_correction_action');
            $table->unsignedBigInteger('manual_correction_required_by')->nullable()->index()->after('manual_correction_reason');
            $table->timestamp('manual_correction_required_at')->nullable()->index()->after('manual_correction_required_by');
        });
    }

    public function down(): void
    {
        Schema::table('pos_returns', function (Blueprint $table) {
            $table->dropColumn([
                'manual_correction_action',
                'manual_correction_reason',
                'manual_correction_required_by',
                'manual_correction_required_at',
            ]);
        });
    }
};