<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Covered tables to add `is_active` column.
     *
     * @var array<string, string>
     */
    protected array $tables = [
        'products' => 'idx_products_is_active',
        'customers' => 'idx_customers_is_active',
        'suppliers' => 'idx_suppliers_is_active',
        'taxes' => 'idx_taxes_is_active',
        'payment_methods' => 'idx_payment_methods_is_active',
        'payment_terms' => 'idx_payment_terms_is_active',
        'locations' => 'idx_locations_is_active',
        'units' => 'idx_units_is_active',
        'chart_of_accounts' => 'idx_chart_of_accounts_is_active',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $tableName => $indexName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'is_active')) {
                Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                    $table->boolean('is_active')->default(true)->after('id');
                    $table->index('is_active', $indexName);
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->tables as $tableName => $indexName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'is_active')) {
                Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                    $table->dropIndex($indexName);
                    $table->dropColumn('is_active');
                });
            }
        }
    }
};
