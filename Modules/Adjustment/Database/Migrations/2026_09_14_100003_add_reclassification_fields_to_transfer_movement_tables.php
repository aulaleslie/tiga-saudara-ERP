<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_movement_lines', function (Blueprint $table) {
            if (!Schema::hasColumn('transfer_movement_lines', 'source_allocation')) {
                $table->json('source_allocation')->nullable()->after('inventory_transaction_reference');
            }
            if (!Schema::hasColumn('transfer_movement_lines', 'destination_classification')) {
                $table->string('destination_classification', 20)->nullable()->after('source_allocation');
            }
        });

        Schema::table('transfer_movement_serials', function (Blueprint $table) {
            if (!Schema::hasColumn('transfer_movement_serials', 'previous_tax_id')) {
                $table->foreignId('previous_tax_id')->nullable()->after('tax_id')->constrained('taxes')->nullOnDelete();
            }
            if (!Schema::hasColumn('transfer_movement_serials', 'previous_tax_name')) {
                $table->string('previous_tax_name')->nullable()->after('previous_tax_id');
            }
            if (!Schema::hasColumn('transfer_movement_serials', 'previous_tax_rate')) {
                $table->decimal('previous_tax_rate', 8, 4)->nullable()->after('previous_tax_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transfer_movement_serials', function (Blueprint $table) {
            foreach (['previous_tax_id', 'previous_tax_name', 'previous_tax_rate'] as $column) {
                if (Schema::hasColumn('transfer_movement_serials', $column)) {
                    if ($column === 'previous_tax_id') {
                        $table->dropConstrainedForeignId($column);
                    } else {
                        $table->dropColumn($column);
                    }
                }
            }
        });

        Schema::table('transfer_movement_lines', function (Blueprint $table) {
            foreach (['source_allocation', 'destination_classification'] as $column) {
                if (Schema::hasColumn('transfer_movement_lines', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
