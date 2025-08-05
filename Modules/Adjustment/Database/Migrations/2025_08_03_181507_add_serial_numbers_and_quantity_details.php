<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // transfers: ensure received tracking (if not already added)
        Schema::table('transfers', function (Blueprint $table) {
            if (!Schema::hasColumn('transfers', 'received_at')) {
                $table->timestamp('received_at')->nullable()->after('dispatched_at');
            }
            if (!Schema::hasColumn('transfers', 'received_by')) {
                $table->unsignedBigInteger('received_by')->nullable()->after('received_at');
                $table->index('received_by', 'transfers_received_by_index');
                $table->foreign('received_by', 'transfers_received_by_foreign')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }
        });

        // transfer_products: add serial/quantity breakdowns + dispatch info
        Schema::table('transfer_products', function (Blueprint $table) {
            if (!Schema::hasColumn('transfer_products', 'serial_numbers')) {
                $table->json('serial_numbers')->nullable()->after('quantity');
            }
            if (!Schema::hasColumn('transfer_products', 'quantity_tax')) {
                $table->unsignedInteger('quantity_tax')->default(0)->after('serial_numbers');
            }
            if (!Schema::hasColumn('transfer_products', 'quantity_non_tax')) {
                $table->unsignedInteger('quantity_non_tax')->default(0)->after('quantity_tax');
            }
            if (!Schema::hasColumn('transfer_products', 'quantity_broken_tax')) {
                $table->unsignedInteger('quantity_broken_tax')->default(0)->after('quantity_non_tax');
            }
            if (!Schema::hasColumn('transfer_products', 'quantity_broken_non_tax')) {
                $table->unsignedInteger('quantity_broken_non_tax')->default(0)->after('quantity_broken_tax');
            }

            // dispatched info
            if (!Schema::hasColumn('transfer_products', 'dispatched_at')) {
                $table->timestamp('dispatched_at')->nullable()->after('updated_at');
            }
            if (!Schema::hasColumn('transfer_products', 'dispatched_by')) {
                $table->unsignedBigInteger('dispatched_by')->nullable()->after('dispatched_at');
                $table->index('dispatched_by', 'transfer_products_dispatched_by_index');
                $table->foreign('dispatched_by', 'transfer_products_dispatched_by_foreign')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            }
            if (!Schema::hasColumn('transfer_products', 'dispatched_quantity')) {
                $table->unsignedInteger('dispatched_quantity')->default(0)->after('dispatched_by');
            }
            if (!Schema::hasColumn('transfer_products', 'dispatched_quantity_tax')) {
                $table->unsignedInteger('dispatched_quantity_tax')->default(0)->after('dispatched_quantity');
            }
            if (!Schema::hasColumn('transfer_products', 'dispatched_quantity_non_tax')) {
                $table->unsignedInteger('dispatched_quantity_non_tax')->default(0)->after('dispatched_quantity_tax');
            }
            if (!Schema::hasColumn('transfer_products', 'dispatched_quantity_broken_tax')) {
                $table->unsignedInteger('dispatched_quantity_broken_tax')->default(0)->after('dispatched_quantity_non_tax');
            }
            if (!Schema::hasColumn('transfer_products', 'dispatched_quantity_broken_non_tax')) {
                $table->unsignedInteger('dispatched_quantity_broken_non_tax')->default(0)->after('dispatched_quantity_broken_tax');
            }
            if (!Schema::hasColumn('transfer_products', 'dispatched_serial_numbers')) {
                $table->json('dispatched_serial_numbers')->nullable()->after('dispatched_quantity_broken_non_tax');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transfer_products', function (Blueprint $table) {
            if (Schema::hasColumn('transfer_products', 'dispatched_serial_numbers')) {
                $table->dropColumn('dispatched_serial_numbers');
            }
            if (Schema::hasColumn('transfer_products', 'dispatched_quantity_broken_non_tax')) {
                $table->dropColumn('dispatched_quantity_broken_non_tax');
            }
            if (Schema::hasColumn('transfer_products', 'dispatched_quantity_broken_tax')) {
                $table->dropColumn('dispatched_quantity_broken_tax');
            }
            if (Schema::hasColumn('transfer_products', 'dispatched_quantity_non_tax')) {
                $table->dropColumn('dispatched_quantity_non_tax');
            }
            if (Schema::hasColumn('transfer_products', 'dispatched_quantity_tax')) {
                $table->dropColumn('dispatched_quantity_tax');
            }
            if (Schema::hasColumn('transfer_products', 'dispatched_quantity')) {
                $table->dropColumn('dispatched_quantity');
            }
            if (Schema::hasColumn('transfer_products', 'dispatched_by')) {
                $table->dropForeign('transfer_products_dispatched_by_foreign');
                $table->dropIndex('transfer_products_dispatched_by_index');
                $table->dropColumn('dispatched_by');
            }
            if (Schema::hasColumn('transfer_products', 'dispatched_at')) {
                $table->dropColumn('dispatched_at');
            }

            // original additions
            if (Schema::hasColumn('transfer_products', 'quantity_broken_non_tax')) {
                $table->dropColumn('quantity_broken_non_tax');
            }
            if (Schema::hasColumn('transfer_products', 'quantity_broken_tax')) {
                $table->dropColumn('quantity_broken_tax');
            }
            if (Schema::hasColumn('transfer_products', 'quantity_non_tax')) {
                $table->dropColumn('quantity_non_tax');
            }
            if (Schema::hasColumn('transfer_products', 'quantity_tax')) {
                $table->dropColumn('quantity_tax');
            }
            if (Schema::hasColumn('transfer_products', 'serial_numbers')) {
                $table->dropColumn('serial_numbers');
            }
        });

        Schema::table('transfers', function (Blueprint $table) {
            if (Schema::hasColumn('transfers', 'received_by')) {
                $table->dropForeign('transfers_received_by_foreign');
                $table->dropIndex('transfers_received_by_index');
                $table->dropColumn('received_by');
            }
            if (Schema::hasColumn('transfers', 'received_at')) {
                $table->dropColumn('received_at');
            }
        });
    }
};
