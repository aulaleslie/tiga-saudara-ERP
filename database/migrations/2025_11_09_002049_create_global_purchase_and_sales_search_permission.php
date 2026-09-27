<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Create permission for accessing the Global Purchase and Sales Search feature.
     * This permission allows users to perform unified searches across both purchase
     * and sales transactions, including serial number tracking and cross-tenant searches.
     */
    public function up(): void
    {
        Permission::create(['name' => 'globalPurchaseAndSalesSearch.access', 'guard_name' => 'web']);
    }

    /**
     * Reverse the migrations.
     *
     * Remove the global purchase and sales search permission.
     */
    public function down(): void
    {
        Permission::where('name', 'globalPurchaseAndSalesSearch.access')->delete();
    }
};
