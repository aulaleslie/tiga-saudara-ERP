<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();
        
        // Helper function to check if index exists (only if table exists)
        $indexExists = function($table, $indexName) use ($driver) {
            if (!Schema::hasTable($table)) {
                return false; // Table doesn't exist, so index doesn't exist
            }
            
            if ($driver === 'mysql') {
                $result = DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$indexName]);
                return !empty($result);
            } elseif ($driver === 'pgsql') {
                $result = DB::select("SELECT 1 FROM pg_indexes WHERE indexname = ?", [$indexName]);
                return !empty($result);
            } elseif ($driver === 'sqlite') {
                $indexes = DB::select("PRAGMA index_list('{$table}')");
                foreach ($indexes as $index) {
                    if ($index->name === $indexName) {
                        return true;
                    }
                }
                return false;
            }
            return false;
        };


        // Add index on settings.company_name for faster tenant lookups
        if (Schema::hasTable('settings') && !$indexExists('settings', 'idx_settings_company_name')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->index('company_name', 'idx_settings_company_name');
            });
        }

        // Add composite index on product_prices for faster lookups
        if (Schema::hasTable('product_prices') && !$indexExists('product_prices', 'idx_product_prices_product_setting')) {
            Schema::table('product_prices', function (Blueprint $table) {
                $table->index(['product_id', 'setting_id'], 'idx_product_prices_product_setting');
            });
        }

        // Add composite index on product_stocks for faster lookups
        if (Schema::hasTable('product_stocks') && !$indexExists('product_stocks', 'idx_product_stocks_product_location')) {
            Schema::table('product_stocks', function (Blueprint $table) {
                $table->index(['product_id', 'location_id'], 'idx_product_stocks_product_location');
            });
        }

        // Add index on taxes.value for faster lookups
        if (Schema::hasTable('taxes') && !$indexExists('taxes', 'idx_taxes_value')) {
            Schema::table('taxes', function (Blueprint $table) {
                $table->index('value', 'idx_taxes_value');
            });
        }

        // For case-insensitive searches, we need to handle differently based on DB driver
        if ($driver === 'pgsql') {
            // PostgreSQL: Create expression indexes for LOWER() lookups
            if (Schema::hasTable('customers')) {
                DB::statement('CREATE INDEX IF NOT EXISTS idx_customers_name_lower ON customers (LOWER(customer_name))');
            }
            if (Schema::hasTable('products')) {
                DB::statement('CREATE INDEX IF NOT EXISTS idx_products_name_lower ON products (LOWER(product_name))');
            }
            if (Schema::hasTable('units')) {
                DB::statement('CREATE INDEX IF NOT EXISTS idx_units_short_name_lower ON units (LOWER(short_name))');
            }
            if (Schema::hasTable('suppliers')) {
                DB::statement('CREATE INDEX IF NOT EXISTS idx_suppliers_name_lower ON suppliers (LOWER(supplier_name))');
            }
        } else {
            // MySQL/MariaDB: Regular indexes (case-insensitive by default for utf8mb4_unicode_ci)
            if (Schema::hasTable('customers') && !$indexExists('customers', 'idx_customers_name')) {
                Schema::table('customers', function (Blueprint $table) {
                    $table->index('customer_name', 'idx_customers_name');
                });
            }
            
            if (Schema::hasTable('products') && !$indexExists('products', 'idx_products_name')) {
                Schema::table('products', function (Blueprint $table) {
                    $table->index('product_name', 'idx_products_name');
                });
            }
            
            if (Schema::hasTable('units') && !$indexExists('units', 'idx_units_short_name')) {
                Schema::table('units', function (Blueprint $table) {
                    $table->index('short_name', 'idx_units_short_name');
                });
            }
            
            if (Schema::hasTable('suppliers') && !$indexExists('suppliers', 'idx_suppliers_name')) {
                Schema::table('suppliers', function (Blueprint $table) {
                    $table->index('supplier_name', 'idx_suppliers_name');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropIndex('idx_settings_company_name');
        });

        Schema::table('product_prices', function (Blueprint $table) {
            $table->dropIndex('idx_product_prices_product_setting');
        });

        Schema::table('product_stocks', function (Blueprint $table) {
            $table->dropIndex('idx_product_stocks_product_location');
        });

        Schema::table('taxes', function (Blueprint $table) {
            $table->dropIndex('idx_taxes_value');
        });

        $driver = DB::getDriverName();
        
        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS idx_customers_name_lower');
            DB::statement('DROP INDEX IF EXISTS idx_products_name_lower');
            DB::statement('DROP INDEX IF EXISTS idx_units_short_name_lower');
            DB::statement('DROP INDEX IF EXISTS idx_suppliers_name_lower');
        } else {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropIndex('idx_customers_name');
            });
            
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex('idx_products_name');
            });
            
            Schema::table('units', function (Blueprint $table) {
                $table->dropIndex('idx_units_short_name');
            });
            
            Schema::table('suppliers', function (Blueprint $table) {
                $table->dropIndex('idx_suppliers_name');
            });
        }
    }
};
