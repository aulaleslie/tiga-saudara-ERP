<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('settings', 'pos_receipt_show_note')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('pos_receipt_show_note')
                ->default(false)
                ->after('row_total_rounding_increment');
            $table->boolean('pos_receipt_show_return_history')
                ->default(false)
                ->after('pos_receipt_show_note');
            $table->boolean('pos_receipt_show_price_adjustments')
                ->default(false)
                ->after('pos_receipt_show_return_history');
            $table->boolean('pos_receipt_show_ppn')
                ->default(false)
                ->after('pos_receipt_show_price_adjustments');
            $table->boolean('pos_receipt_show_signature_space')
                ->default(true)
                ->after('pos_receipt_show_ppn');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('settings', 'pos_receipt_show_note')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'pos_receipt_show_note',
                'pos_receipt_show_return_history',
                'pos_receipt_show_price_adjustments',
                'pos_receipt_show_ppn',
                'pos_receipt_show_signature_space',
            ]);
        });
    }
};
