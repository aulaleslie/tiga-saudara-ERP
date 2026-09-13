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
        if (Schema::hasTable('transfer_movements')) {
            Schema::table('transfer_movements', function (Blueprint $table) {
                if (!Schema::hasColumn('transfer_movements', 'empty_count_confirmed')) {
                    $table->boolean('empty_count_confirmed')->default(false)->after('cancellation_reason');
                }
                if (!Schema::hasColumn('transfer_movements', 'empty_count_confirmed_by')) {
                    $table->foreignId('empty_count_confirmed_by')->nullable()->after('empty_count_confirmed')->constrained('users')->nullOnDelete();
                }
                if (!Schema::hasColumn('transfer_movements', 'empty_count_confirmed_at')) {
                    $table->timestamp('empty_count_confirmed_at')->nullable()->after('empty_count_confirmed_by');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('transfer_movements')) {
            Schema::table('transfer_movements', function (Blueprint $table) {
                if (Schema::hasColumn('transfer_movements', 'empty_count_confirmed_by')) {
                    $table->dropForeign(['empty_count_confirmed_by']);
                }
                $columns = [
                    'empty_count_confirmed',
                    'empty_count_confirmed_by',
                    'empty_count_confirmed_at',
                ];
                foreach ($columns as $col) {
                    if (Schema::hasColumn('transfer_movements', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
