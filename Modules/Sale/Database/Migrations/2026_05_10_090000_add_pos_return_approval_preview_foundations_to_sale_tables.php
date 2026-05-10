<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sale_payments')) {
            Schema::table('sale_payments', function (Blueprint $table) {
                if (! Schema::hasColumn('sale_payments', 'status')) {
                    $table->string('status')->default('ACTIVE')->after('note');
                }

                if (! Schema::hasColumn('sale_payments', 'invalidated_at')) {
                    $table->timestamp('invalidated_at')->nullable()->after('status');
                }

                if (! Schema::hasColumn('sale_payments', 'invalidated_by')) {
                    $table->foreignId('invalidated_by')->nullable()->after('invalidated_at')->constrained('users')->nullOnDelete();
                }

                if (! Schema::hasColumn('sale_payments', 'invalidation_source')) {
                    $table->string('invalidation_source')->nullable()->after('invalidated_by');
                }

                if (! Schema::hasColumn('sale_payments', 'invalidation_source_id')) {
                    $table->unsignedBigInteger('invalidation_source_id')->nullable()->after('invalidation_source');
                }
            });

            Schema::table('sale_payments', function (Blueprint $table) {
                $table->index('status', 'sale_payments_status_idx');
                $table->index(['sale_id', 'status'], 'sale_payments_sale_status_idx');
            });
        }

        if (Schema::hasTable('dispatch_details')) {
            Schema::table('dispatch_details', function (Blueprint $table) {
                if (! Schema::hasColumn('dispatch_details', 'pos_return_line_id')) {
                    $table->unsignedBigInteger('pos_return_line_id')->nullable()->after('bundle_id');
                }

                if (! Schema::hasColumn('dispatch_details', 'replacement_of_dispatch_detail_id')) {
                    $table->unsignedBigInteger('replacement_of_dispatch_detail_id')->nullable()->after('pos_return_line_id');
                }

                if (! Schema::hasColumn('dispatch_details', 'replacement_returned_serial_id')) {
                    $table->unsignedBigInteger('replacement_returned_serial_id')->nullable()->after('replacement_of_dispatch_detail_id');
                }
            });

            Schema::table('dispatch_details', function (Blueprint $table) {
                $table->index('pos_return_line_id', 'dispatch_details_pos_return_line_idx');
                $table->index('replacement_of_dispatch_detail_id', 'dispatch_details_replacement_source_idx');
                $table->index('replacement_returned_serial_id', 'dispatch_details_replacement_returned_serial_idx');

                if (
                    Schema::hasColumn('dispatch_details', 'sale_id')
                    && Schema::hasColumn('dispatch_details', 'product_id')
                    && Schema::hasColumn('dispatch_details', 'location_id')
                    && Schema::hasColumn('dispatch_details', 'tax_id')
                ) {
                    $table->index(
                        ['sale_id', 'product_id', 'location_id', 'tax_id'],
                        'dispatch_details_sale_product_location_tax_idx'
                    );
                }
            });
        }

        if (Schema::hasTable('sales_order_serial_tracking')) {
            Schema::table('sales_order_serial_tracking', function (Blueprint $table) {
                $table->index(['sale_id', 'return_date'], 'sales_order_serial_tracking_sale_return_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_order_serial_tracking')) {
            Schema::table('sales_order_serial_tracking', function (Blueprint $table) {
                $table->dropIndex('sales_order_serial_tracking_sale_return_idx');
            });
        }

        if (Schema::hasTable('dispatch_details')) {
            Schema::table('dispatch_details', function (Blueprint $table) {
                $table->dropIndex('dispatch_details_pos_return_line_idx');
                $table->dropIndex('dispatch_details_replacement_source_idx');
                $table->dropIndex('dispatch_details_replacement_returned_serial_idx');

                if (
                    Schema::hasColumn('dispatch_details', 'sale_id')
                    && Schema::hasColumn('dispatch_details', 'product_id')
                    && Schema::hasColumn('dispatch_details', 'location_id')
                    && Schema::hasColumn('dispatch_details', 'tax_id')
                ) {
                    $table->dropIndex('dispatch_details_sale_product_location_tax_idx');
                }

                if (Schema::hasColumn('dispatch_details', 'replacement_returned_serial_id')) {
                    $table->dropColumn('replacement_returned_serial_id');
                }

                if (Schema::hasColumn('dispatch_details', 'replacement_of_dispatch_detail_id')) {
                    $table->dropColumn('replacement_of_dispatch_detail_id');
                }

                if (Schema::hasColumn('dispatch_details', 'pos_return_line_id')) {
                    $table->dropColumn('pos_return_line_id');
                }
            });
        }

        if (Schema::hasTable('sale_payments')) {
            Schema::table('sale_payments', function (Blueprint $table) {
                $table->dropIndex('sale_payments_status_idx');
                $table->dropIndex('sale_payments_sale_status_idx');

                if (Schema::hasColumn('sale_payments', 'invalidated_by')) {
                    $table->dropForeign(['invalidated_by']);
                }

                $columns = [];

                foreach (['status', 'invalidated_at', 'invalidated_by', 'invalidation_source', 'invalidation_source_id'] as $column) {
                    if (Schema::hasColumn('sale_payments', $column)) {
                        $columns[] = $column;
                    }
                }

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};