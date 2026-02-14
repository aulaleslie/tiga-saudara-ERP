<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            $table->boolean('is_default')->default(false);
            $table->index('is_default', 'idx_taxes_is_default');
        });

        $hasDefault = DB::table('taxes')->where('is_default', true)->exists();

        if (! $hasDefault) {
            $oldestTaxId = DB::table('taxes')->orderBy('id')->value('id');

            if ($oldestTaxId !== null) {
                DB::table('taxes')
                    ->where('id', $oldestTaxId)
                    ->update(['is_default' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            $table->dropIndex('idx_taxes_is_default');
            $table->dropColumn('is_default');
        });
    }
};
