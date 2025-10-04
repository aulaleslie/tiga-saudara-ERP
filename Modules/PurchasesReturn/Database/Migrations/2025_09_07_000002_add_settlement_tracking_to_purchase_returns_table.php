<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_returns', 'settled_at')) {
                $table->timestamp('settled_at')->nullable()->after('approved_at');
            }

            if (! Schema::hasColumn('purchase_returns', 'settled_by')) {
                $table->foreignId('settled_by')
                    ->nullable()
                    ->after('settled_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_returns', 'settled_by')) {
                $table->dropConstrainedForeignId('settled_by');
            }

            if (Schema::hasColumn('purchase_returns', 'settled_at')) {
                $table->dropColumn('settled_at');
            }
        });
    }
};
