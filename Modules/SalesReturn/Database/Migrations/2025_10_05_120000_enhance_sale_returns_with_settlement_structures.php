<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_returns', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_returns', 'sale_id')) {
                $table->foreignId('sale_id')
                    ->nullable()
                    ->after('reference')
                    ->constrained('sales')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('sale_returns', 'sale_reference')) {
                $table->string('sale_reference')
                    ->nullable()
                    ->after('sale_id');
            }

            if (! Schema::hasColumn('sale_returns', 'setting_id')) {
                $table->foreignId('setting_id')
                    ->nullable()
                    ->after('customer_id')
                    ->constrained('settings')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('sale_returns', 'location_id')) {
                $table->foreignId('location_id')
                    ->nullable()
                    ->after('setting_id')
                    ->constrained('locations')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('sale_returns', 'cash_proof_path')) {
                $table->string('cash_proof_path')
                    ->nullable()
                    ->after('payment_method');
            }

            if (! Schema::hasColumn('sale_returns', 'approval_status')) {
                $table->string('approval_status')
                    ->default('draft')
                    ->after('due_amount');
                $table->index('approval_status');
            }

            if (! Schema::hasColumn('sale_returns', 'return_type')) {
                $table->string('return_type')
                    ->nullable()
                    ->after('approval_status');
                $table->index('return_type');
            }

            if (! Schema::hasColumn('sale_returns', 'approved_by')) {
                $table->foreignId('approved_by')
                    ->nullable()
                    ->after('return_type')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('sale_returns', 'approved_at')) {
                $table->timestamp('approved_at')
                    ->nullable()
                    ->after('approved_by');
            }

            if (! Schema::hasColumn('sale_returns', 'rejected_by')) {
                $table->foreignId('rejected_by')
                    ->nullable()
                    ->after('approved_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('sale_returns', 'rejected_at')) {
                $table->timestamp('rejected_at')
                    ->nullable()
                    ->after('rejected_by');
            }

            if (! Schema::hasColumn('sale_returns', 'rejection_reason')) {
                $table->text('rejection_reason')
                    ->nullable()
                    ->after('rejected_at');
            }

            if (! Schema::hasColumn('sale_returns', 'settled_at')) {
                $table->timestamp('settled_at')
                    ->nullable()
                    ->after('rejection_reason');
            }

            if (! Schema::hasColumn('sale_returns', 'settled_by')) {
                $table->foreignId('settled_by')
                    ->nullable()
                    ->after('settled_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });

        // Backfill cent-based money columns before type change.
        if (Schema::hasTable('sale_returns')) {
            DB::table('sale_returns')->update([
                'tax_amount' => DB::raw('tax_amount / 100'),
                'discount_amount' => DB::raw('discount_amount / 100'),
                'shipping_amount' => DB::raw('shipping_amount / 100'),
                'total_amount' => DB::raw('total_amount / 100'),
                'paid_amount' => DB::raw('paid_amount / 100'),
                'due_amount' => DB::raw('due_amount / 100'),
            ]);

            DB::statement("ALTER TABLE sale_returns
                MODIFY tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                MODIFY discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                MODIFY shipping_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                MODIFY total_amount DECIMAL(15,2) NOT NULL,
                MODIFY paid_amount DECIMAL(15,2) NOT NULL,
                MODIFY due_amount DECIMAL(15,2) NOT NULL");
        }

        Schema::table('sale_return_details', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_return_details', 'sale_detail_id')) {
                $table->foreignId('sale_detail_id')
                    ->nullable()
                    ->after('sale_return_id')
                    ->constrained('sale_details')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('sale_return_details', 'dispatch_detail_id')) {
                $table->foreignId('dispatch_detail_id')
                    ->nullable()
                    ->after('sale_detail_id')
                    ->constrained('dispatch_details')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('sale_return_details', 'location_id')) {
                $table->foreignId('location_id')
                    ->nullable()
                    ->after('dispatch_detail_id')
                    ->constrained('locations')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('sale_return_details', 'tax_id')) {
                $table->foreignId('tax_id')
                    ->nullable()
                    ->after('product_tax_amount')
                    ->constrained('taxes')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('sale_return_details', 'serial_number_ids')) {
                $table->json('serial_number_ids')
                    ->nullable()
                    ->after('tax_id');
            }
        });

        if (Schema::hasTable('sale_return_details')) {
            DB::table('sale_return_details')->update([
                'price' => DB::raw('price / 100'),
                'unit_price' => DB::raw('unit_price / 100'),
                'sub_total' => DB::raw('sub_total / 100'),
                'product_discount_amount' => DB::raw('product_discount_amount / 100'),
                'product_tax_amount' => DB::raw('product_tax_amount / 100'),
            ]);

            DB::statement("ALTER TABLE sale_return_details
                MODIFY price DECIMAL(15,2) NOT NULL,
                MODIFY unit_price DECIMAL(15,2) NOT NULL,
                MODIFY sub_total DECIMAL(15,2) NOT NULL,
                MODIFY product_discount_amount DECIMAL(15,2) NOT NULL,
                MODIFY product_tax_amount DECIMAL(15,2) NOT NULL");
        }

        if (! Schema::hasTable('sale_return_goods')) {
            Schema::create('sale_return_goods', function (Blueprint $table) {
                $table->id();

                $table->foreignId('sale_return_id')
                    ->constrained('sale_returns')
                    ->cascadeOnDelete();

                $table->foreignId('product_id')
                    ->nullable()
                    ->constrained('products')
                    ->nullOnDelete();

                $table->string('product_name');
                $table->string('product_code')->nullable();
                $table->integer('quantity');
                $table->decimal('unit_value', 15, 2)->nullable();
                $table->decimal('sub_total', 15, 2)->nullable();
                $table->timestamp('received_at')->nullable();
                $table->timestamps();

                $table->index(['sale_return_id']);
                $table->index(['product_id']);
                $table->index(['sale_return_id', 'received_at'], 'srg_return_received_idx');
            });
        }

        if (! Schema::hasTable('customer_credits')) {
            Schema::create('customer_credits', function (Blueprint $table) {
                $table->id();

                $table->foreignId('customer_id')
                    ->constrained('customers')
                    ->cascadeOnDelete();

                $table->foreignId('sale_return_id')
                    ->unique()
                    ->constrained('sale_returns')
                    ->cascadeOnDelete();

                $table->decimal('amount', 15, 2);
                $table->decimal('remaining_amount', 15, 2);
                $table->string('status')->default('open');
                $table->timestamps();

                $table->index(['customer_id', 'status']);
            });
        }

        if (! Schema::hasTable('sale_payment_credit_applications')) {
            Schema::create('sale_payment_credit_applications', function (Blueprint $table) {
                $table->id();

                $table->foreignId('sale_payment_id')
                    ->constrained('sale_payments')
                    ->cascadeOnDelete();

                $table->foreignId('customer_credit_id')
                    ->constrained('customer_credits')
                    ->cascadeOnDelete();

                $table->decimal('amount', 15, 2);
                $table->timestamps();

                $table->unique(['sale_payment_id', 'customer_credit_id'], 'uniq_sale_payment_credit');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payment_credit_applications');
        Schema::dropIfExists('customer_credits');
        Schema::dropIfExists('sale_return_goods');

        if (Schema::hasTable('sale_return_details')) {
            DB::table('sale_return_details')->update([
                'price' => DB::raw('price * 100'),
                'unit_price' => DB::raw('unit_price * 100'),
                'sub_total' => DB::raw('sub_total * 100'),
                'product_discount_amount' => DB::raw('product_discount_amount * 100'),
                'product_tax_amount' => DB::raw('product_tax_amount * 100'),
            ]);

            DB::statement("ALTER TABLE sale_return_details
                MODIFY price INT NOT NULL,
                MODIFY unit_price INT NOT NULL,
                MODIFY sub_total INT NOT NULL,
                MODIFY product_discount_amount INT NOT NULL,
                MODIFY product_tax_amount INT NOT NULL");
        }

        Schema::table('sale_return_details', function (Blueprint $table) {
            if (Schema::hasColumn('sale_return_details', 'serial_number_ids')) {
                $table->dropColumn('serial_number_ids');
            }

            if (Schema::hasColumn('sale_return_details', 'tax_id')) {
                $table->dropConstrainedForeignId('tax_id');
            }

            if (Schema::hasColumn('sale_return_details', 'location_id')) {
                $table->dropConstrainedForeignId('location_id');
            }

            if (Schema::hasColumn('sale_return_details', 'dispatch_detail_id')) {
                $table->dropConstrainedForeignId('dispatch_detail_id');
            }

            if (Schema::hasColumn('sale_return_details', 'sale_detail_id')) {
                $table->dropConstrainedForeignId('sale_detail_id');
            }
        });

        if (Schema::hasTable('sale_returns')) {
            DB::table('sale_returns')->update([
                'tax_amount' => DB::raw('tax_amount * 100'),
                'discount_amount' => DB::raw('discount_amount * 100'),
                'shipping_amount' => DB::raw('shipping_amount * 100'),
                'total_amount' => DB::raw('total_amount * 100'),
                'paid_amount' => DB::raw('paid_amount * 100'),
                'due_amount' => DB::raw('due_amount * 100'),
            ]);

            DB::statement("ALTER TABLE sale_returns
                MODIFY tax_amount INT NOT NULL DEFAULT 0,
                MODIFY discount_amount INT NOT NULL DEFAULT 0,
                MODIFY shipping_amount INT NOT NULL DEFAULT 0,
                MODIFY total_amount INT NOT NULL,
                MODIFY paid_amount INT NOT NULL,
                MODIFY due_amount INT NOT NULL");
        }

        Schema::table('sale_returns', function (Blueprint $table) {
            if (Schema::hasColumn('sale_returns', 'settled_by')) {
                $table->dropConstrainedForeignId('settled_by');
            }

            if (Schema::hasColumn('sale_returns', 'settled_at')) {
                $table->dropColumn('settled_at');
            }

            if (Schema::hasColumn('sale_returns', 'rejection_reason')) {
                $table->dropColumn('rejection_reason');
            }

            if (Schema::hasColumn('sale_returns', 'rejected_at')) {
                $table->dropColumn('rejected_at');
            }

            if (Schema::hasColumn('sale_returns', 'rejected_by')) {
                $table->dropConstrainedForeignId('rejected_by');
            }

            if (Schema::hasColumn('sale_returns', 'approved_at')) {
                $table->dropColumn('approved_at');
            }

            if (Schema::hasColumn('sale_returns', 'approved_by')) {
                $table->dropConstrainedForeignId('approved_by');
            }

            if (Schema::hasColumn('sale_returns', 'return_type')) {
                $table->dropColumn('return_type');
            }

            if (Schema::hasColumn('sale_returns', 'approval_status')) {
                $table->dropColumn('approval_status');
            }

            if (Schema::hasColumn('sale_returns', 'cash_proof_path')) {
                $table->dropColumn('cash_proof_path');
            }

            if (Schema::hasColumn('sale_returns', 'location_id')) {
                $table->dropConstrainedForeignId('location_id');
            }

            if (Schema::hasColumn('sale_returns', 'setting_id')) {
                $table->dropConstrainedForeignId('setting_id');
            }

            if (Schema::hasColumn('sale_returns', 'sale_reference')) {
                $table->dropColumn('sale_reference');
            }

            if (Schema::hasColumn('sale_returns', 'sale_id')) {
                $table->dropConstrainedForeignId('sale_id');
            }
        });
    }
};
