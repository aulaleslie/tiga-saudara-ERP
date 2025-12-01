<?php

namespace Modules\User\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionsTableSeeder extends Seeder
{
    public function run()
    {
        $permissions = [
            // Adjustments
            'adjustments.access',
            'adjustments.approval',
            'adjustments.breakage.approval',
            'adjustments.breakage.create',
            'adjustments.breakage.edit',
            'adjustments.create',
            'adjustments.delete',
            'adjustments.edit',
            'adjustments.show',
            'adjustments.reject',

            // Barcode
            'barcodes.print',

            // Brands
            'brands.access',
            'brands.create',
            'brands.edit',
            'brands.delete',
            'brands.view',

            // Businesses
            'businesses.access',
            'businesses.create',
            'businesses.edit',
            'businesses.delete',
            'businesses.show',

            // Categories
            'categories.access',
            'categories.create',
            'categories.edit',
            'categories.delete',

            // Chart of Accounts
            'chartOfAccounts.access',
            'chartOfAccounts.create',
            'chartOfAccounts.edit',
            'chartOfAccounts.delete',
            'chartOfAccounts.show',

            // Currencies
            'currencies.access',
            'currencies.create',
            'currencies.edit',
            'currencies.delete',

            // Customers
            'customers.access',
            'customers.create',
            'customers.edit',
            'customers.delete',
            'customers.show',

            // Expense Categories
            'expenseCategories.access',
            'expenseCategories.create',
            'expenseCategories.edit',
            'expenseCategories.delete',

            // Expenses
            'expenses.access',
            'expenses.create',
            'expenses.edit',
            'expenses.delete',

            // Journals
            'journals.access',
            'journals.create',
            'journals.edit',
            'journals.delete',
            'journals.show',

            // Locations
            'locations.access',
            'locations.create',
            'locations.edit',

            // Sale location configuration
            'saleLocations.access',
            'saleLocations.edit',

            // Payment Methods / Terms
            'paymentMethods.access',
            'paymentMethods.create',
            'paymentMethods.edit',
            'paymentMethods.delete',
            'paymentTerms.access',
            'paymentTerms.create',
            'paymentTerms.edit',
            'paymentTerms.delete',

            // POS
            'pos.access',
            'pos.create',
            'pos.transactions.access',

            // Products & bundles
            'products.access',
            'products.create',
            'products.edit',
            'products.delete',
            'products.show',
            'products.bundle.access',
            'products.bundle.create',
            'products.bundle.edit',
            'products.bundle.delete',

            // Profiles
            'profiles.edit',

            // Purchases & related
            'purchases.access',
            'purchases.create',
            'purchases.edit',
            'purchases.delete',
            'purchases.show',
            'purchases.receive',
            'purchases.approval',
            'purchases.view',
            'purchaseReceivings.access',
            'purchaseReports.access',

            // Purchase Payments
            'purchasePayments.access',
            'purchasePayments.create',
            'purchasePayments.edit',
            'purchasePayments.delete',

            // Purchase Returns & Payments
            'purchaseReturns.access',
            'purchaseReturns.create',
            'purchaseReturns.edit',
            'purchaseReturns.delete',
            'purchaseReturns.show',
            'purchaseReturnPayments.access',
            'purchaseReturnPayments.create',
            'purchaseReturnPayments.edit',
            'purchaseReturnPayments.delete',
            'purchaseReturnPayments.show',

            // Reports / Settings
            'reports.access',
            'settings.access',
            'settings.edit',

            // Stock Transfers
            'stockTransfers.access',
            'stockTransfers.create',
            'stockTransfers.edit',
            'stockTransfers.delete',
            'stockTransfers.show',
            'stockTransfers.dispatch',
            'stockTransfers.receive',
            'stockTransfers.approval',

            // Suppliers
            'suppliers.access',
            'suppliers.create',
            'suppliers.edit',
            'suppliers.delete',
            'suppliers.show',

            // Taxes
            'taxes.access',
            'taxes.create',
            'taxes.edit',
            'taxes.delete',

            // Units
            'units.access',
            'units.create',
            'units.edit',
            'units.delete',

            // Users / Roles
            'users.access',
            'users.create',
            'users.edit',
            'users.delete',
            'roles.access',
            'roles.create',
            'roles.edit',
            'roles.delete',

            // Sale Payments / Returns
            'salePayments.access',
            'salePayments.create',
            'salePayments.edit',
            'salePayments.delete',
            'saleReturnPayments.access',
            'saleReturnPayments.create',
            'saleReturnPayments.edit',
            'saleReturnPayments.delete',
            'salePayments.show',

            // Sale Returns / Sales
            'saleReturns.access',
            'saleReturns.create',
            'saleReturns.edit',
            'saleReturns.delete',
            'saleReturns.show',
            'saleReturns.approve',
            'saleReturns.receive',
            'sales.access',
            'sales.create',
            'sales.edit',
            'sales.delete',
            'sales.dispatch',
            'sales.show',
            'sales.approval',

            // Global Sales Search - Track Sales by Serial Number
            'globalSalesSearch.access',

            // Notifications / Misc
            'show_notifications',
        ];

        // Normalize & dedupe
        $permissions = array_values(array_unique($permissions));

        DB::transaction(function () use ($permissions) {
            // ensure canonical permissions exist
            foreach ($permissions as $permission) {
                Permission::firstOrCreate(['name' => $permission]);
            }

            // delete any permission not in canonical list
            Permission::whereNotIn('name', $permissions)->delete();

            // sync admin role to exactly this set
            $role = Role::firstOrCreate(['name' => 'Admin']);
            $role->syncPermissions($permissions);
        });
    }
}
