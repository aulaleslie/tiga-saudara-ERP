<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_return_lines')) {
            Schema::table('pos_return_lines', function (Blueprint $table) {
                if (Schema::hasColumn('pos_return_lines', 'resolution')) {
                    $table->index(['pos_return_id', 'resolution'], 'pos_return_lines_return_resolution_idx');
                }

                if (
                    Schema::hasColumn('pos_return_lines', 'sale_return_id')
                    && Schema::hasColumn('pos_return_lines', 'sale_return_detail_id')
                ) {
                    $table->index(
                        ['sale_return_id', 'sale_return_detail_id'],
                        'pos_return_lines_sale_return_link_idx'
                    );
                }

                if (
                    Schema::hasColumn('pos_return_lines', 'sale_id')
                    && Schema::hasColumn('pos_return_lines', 'dispatch_detail_id')
                ) {
                    $table->index(['sale_id', 'dispatch_detail_id'], 'pos_return_lines_sale_dispatch_idx');
                }
            });
        }

        if (Schema::hasTable('sale_return_details')) {
            Schema::table('sale_return_details', function (Blueprint $table) {
                if (
                    Schema::hasColumn('sale_return_details', 'sale_return_id')
                    && Schema::hasColumn('sale_return_details', 'dispatch_detail_id')
                ) {
                    $table->index(
                        ['sale_return_id', 'dispatch_detail_id'],
                        'sale_return_details_return_dispatch_idx'
                    );
                }

                if (
                    Schema::hasColumn('sale_return_details', 'sale_return_id')
                    && Schema::hasColumn('sale_return_details', 'sale_detail_id')
                ) {
                    $table->index(
                        ['sale_return_id', 'sale_detail_id'],
                        'sale_return_details_return_sale_detail_idx'
                    );
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sale_return_details')) {
            Schema::table('sale_return_details', function (Blueprint $table) {
                if (
                    Schema::hasColumn('sale_return_details', 'sale_return_id')
                    && Schema::hasColumn('sale_return_details', 'dispatch_detail_id')
                ) {
                    $table->dropIndex('sale_return_details_return_dispatch_idx');
                }

                if (
                    Schema::hasColumn('sale_return_details', 'sale_return_id')
                    && Schema::hasColumn('sale_return_details', 'sale_detail_id')
                ) {
                    $table->dropIndex('sale_return_details_return_sale_detail_idx');
                }
            });
        }

        if (Schema::hasTable('pos_return_lines')) {
            Schema::table('pos_return_lines', function (Blueprint $table) {
                if (Schema::hasColumn('pos_return_lines', 'resolution')) {
                    $table->dropIndex('pos_return_lines_return_resolution_idx');
                }

                if (
                    Schema::hasColumn('pos_return_lines', 'sale_return_id')
                    && Schema::hasColumn('pos_return_lines', 'sale_return_detail_id')
                ) {
                    $table->dropIndex('pos_return_lines_sale_return_link_idx');
                }

                if (
                    Schema::hasColumn('pos_return_lines', 'sale_id')
                    && Schema::hasColumn('pos_return_lines', 'dispatch_detail_id')
                ) {
                    $table->dropIndex('pos_return_lines_sale_dispatch_idx');
                }
            });
        }
    }
};