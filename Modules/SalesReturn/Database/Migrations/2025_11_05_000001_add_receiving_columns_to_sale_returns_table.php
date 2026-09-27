<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_returns', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_returns', 'received_by')) {
                $table->foreignId('received_by')
                    ->nullable()
                    ->after('settled_by')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('sale_returns', 'received_at')) {
                $table->timestamp('received_at')
                    ->nullable()
                    ->after('received_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sale_returns', function (Blueprint $table) {
            if (Schema::hasColumn('sale_returns', 'received_at')) {
                $table->dropColumn('received_at');
            }

            if (Schema::hasColumn('sale_returns', 'received_by')) {
                $table->dropConstrainedForeignId('received_by');
            }
        });
    }
};
