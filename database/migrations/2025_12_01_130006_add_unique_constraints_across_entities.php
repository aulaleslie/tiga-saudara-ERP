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
        // Check for existing duplicates before adding constraints
        $this->checkForDuplicates();

        // Product Categories: unique category_name per setting
        Schema::table('categories', function (Blueprint $table) {
            $table->unique(['setting_id', 'category_name'], 'categories_setting_category_name_unique');
        });

        // Brands: unique name per setting (soft deletes handled in application)
        Schema::table('brands', function (Blueprint $table) {
            $table->unique(['setting_id', 'name'], 'brands_setting_name_unique');
        });

        // Units: unique name and short_name per setting
        Schema::table('units', function (Blueprint $table) {
            $table->unique(['setting_id', 'name'], 'units_setting_name_unique');
            $table->unique(['setting_id', 'short_name'], 'units_setting_short_name_unique');
        });

        // Taxes: unique name globally (no setting_id)
        Schema::table('taxes', function (Blueprint $table) {
            $table->unique('name', 'taxes_name_unique');
        });

        // Customers: unique fields per setting
        Schema::table('customers', function (Blueprint $table) {
            $table->unique(['setting_id', 'customer_phone'], 'customers_setting_phone_unique');
            $table->unique(['setting_id', 'customer_email'], 'customers_setting_email_unique');
            $table->unique(['setting_id', 'identity_number'], 'customers_setting_identity_unique');
            $table->unique(['setting_id', 'npwp'], 'customers_setting_npwp_unique');
        });

        // Suppliers: unique fields per setting
        Schema::table('suppliers', function (Blueprint $table) {
            $table->unique(['setting_id', 'supplier_name'], 'suppliers_setting_name_unique');
            $table->unique(['setting_id', 'supplier_phone'], 'suppliers_setting_phone_unique');
            $table->unique(['setting_id', 'supplier_email'], 'suppliers_setting_email_unique');
            $table->unique(['setting_id', 'identity_number'], 'suppliers_setting_identity_unique');
        });

        // Sales documents: unique reference per setting
        Schema::table('sales', function (Blueprint $table) {
            $table->unique(['setting_id', 'reference'], 'sales_setting_reference_unique');
        });

        Schema::table('sale_returns', function (Blueprint $table) {
            $table->unique(['setting_id', 'reference'], 'sale_returns_setting_reference_unique');
        });

        // Quotations: unique reference globally (no setting_id column)
        Schema::table('quotations', function (Blueprint $table) {
            $table->unique('reference', 'quotations_reference_unique');
        });

        // Purchase documents: unique reference per setting
        Schema::table('purchases', function (Blueprint $table) {
            $table->unique(['setting_id', 'reference'], 'purchases_setting_reference_unique');
            // Note: Nullable field uniqueness handled at application level
        });

        // Received Notes: unique fields per PO
        Schema::table('received_notes', function (Blueprint $table) {
            $table->unique(['po_id', 'external_delivery_number'], 'received_notes_po_external_unique');
            // Note: Nullable field uniqueness handled at application level
        });

        // Payment Methods: unique name globally
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->unique('name', 'payment_methods_name_unique');
        });

        // Locations: unique name per setting
        Schema::table('locations', function (Blueprint $table) {
            $table->unique(['setting_id', 'name'], 'locations_setting_name_unique');
        });

        // Settings/Businesses: unique company_name globally
        Schema::table('settings', function (Blueprint $table) {
            $table->unique('company_name', 'settings_company_name_unique');
            // Note: Nullable field uniqueness handled at application level
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop all unique constraints in reverse order
        Schema::table('settings', function (Blueprint $table) {
            $table->dropUnique('settings_company_name_unique');
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->dropUnique('locations_setting_name_unique');
        });

        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropUnique('payment_methods_name_unique');
        });

        Schema::table('received_notes', function (Blueprint $table) {
            $table->dropUnique('received_notes_po_external_unique');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropUnique('purchases_setting_reference_unique');
        });

        Schema::table('quotations', function (Blueprint $table) {
            $table->dropUnique('quotations_reference_unique');
        });

        Schema::table('sale_returns', function (Blueprint $table) {
            $table->dropUnique('sale_returns_setting_reference_unique');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique('sales_setting_reference_unique');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropUnique('suppliers_setting_identity_unique');
            $table->dropUnique('suppliers_setting_email_unique');
            $table->dropUnique('suppliers_setting_phone_unique');
            $table->dropUnique('suppliers_setting_name_unique');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_setting_npwp_unique');
            $table->dropUnique('customers_setting_identity_unique');
            $table->dropUnique('customers_setting_email_unique');
            $table->dropUnique('customers_setting_phone_unique');
        });

        Schema::table('taxes', function (Blueprint $table) {
            $table->dropUnique('taxes_name_unique');
        });

        Schema::table('units', function (Blueprint $table) {
            $table->dropUnique('units_setting_short_name_unique');
            $table->dropUnique('units_setting_name_unique');
        });

        // Drop brands constraint (regular unique index)
        Schema::table('brands', function (Blueprint $table) {
            $table->dropUnique('brands_setting_name_unique');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique('categories_setting_category_name_unique');
        });
    }

    /**
     * Check for existing duplicates before adding constraints
     */
    private function checkForDuplicates(): void
    {
        $errors = [];

        // Check categories
        $dupCategories = DB::select("
            SELECT setting_id, category_name, COUNT(*) as count
            FROM categories
            GROUP BY setting_id, category_name
            HAVING COUNT(*) > 1
        ");
        if ($dupCategories) {
            $errors[] = "Duplicate categories found: " . json_encode($dupCategories);
        }

        // Check brands (excluding soft deleted)
        $dupBrands = DB::select("
            SELECT setting_id, name, COUNT(*) as count
            FROM brands
            WHERE deleted_at IS NULL
            GROUP BY setting_id, name
            HAVING COUNT(*) > 1
        ");
        if ($dupBrands) {
            $errors[] = "Duplicate brands found: " . json_encode($dupBrands);
        }

        // Check units
        $dupUnitsName = DB::select("
            SELECT setting_id, name, COUNT(*) as count
            FROM units
            GROUP BY setting_id, name
            HAVING COUNT(*) > 1
        ");
        if ($dupUnitsName) {
            $errors[] = "Duplicate unit names found: " . json_encode($dupUnitsName);
        }

        $dupUnitsShort = DB::select("
            SELECT setting_id, short_name, COUNT(*) as count
            FROM units
            GROUP BY setting_id, short_name
            HAVING COUNT(*) > 1
        ");
        if ($dupUnitsShort) {
            $errors[] = "Duplicate unit short names found: " . json_encode($dupUnitsShort);
        }

        // Check taxes
        $dupTaxes = DB::select("
            SELECT name, COUNT(*) as count
            FROM taxes
            GROUP BY name
            HAVING COUNT(*) > 1
        ");
        if ($dupTaxes) {
            $errors[] = "Duplicate taxes found: " . json_encode($dupTaxes);
        }

        // Check customers
        $dupCustomerPhones = DB::select("
            SELECT setting_id, customer_phone, COUNT(*) as count
            FROM customers
            WHERE customer_phone IS NOT NULL AND customer_phone != ''
            GROUP BY setting_id, customer_phone
            HAVING COUNT(*) > 1
        ");
        if ($dupCustomerPhones) {
            $errors[] = "Duplicate customer phones found: " . json_encode($dupCustomerPhones);
        }

        $dupCustomerEmails = DB::select("
            SELECT setting_id, customer_email, COUNT(*) as count
            FROM customers
            WHERE customer_email IS NOT NULL AND customer_email != ''
            GROUP BY setting_id, customer_email
            HAVING COUNT(*) > 1
        ");
        if ($dupCustomerEmails) {
            $errors[] = "Duplicate customer emails found: " . json_encode($dupCustomerEmails);
        }

        $dupCustomerIdentities = DB::select("
            SELECT setting_id, identity_number, COUNT(*) as count
            FROM customers
            WHERE identity_number IS NOT NULL AND identity_number != ''
            GROUP BY setting_id, identity_number
            HAVING COUNT(*) > 1
        ");
        if ($dupCustomerIdentities) {
            $errors[] = "Duplicate customer identity numbers found: " . json_encode($dupCustomerIdentities);
        }

        $dupCustomerNpwp = DB::select("
            SELECT setting_id, npwp, COUNT(*) as count
            FROM customers
            WHERE npwp IS NOT NULL AND npwp != ''
            GROUP BY setting_id, npwp
            HAVING COUNT(*) > 1
        ");
        if ($dupCustomerNpwp) {
            $errors[] = "Duplicate customer NPWP found: " . json_encode($dupCustomerNpwp);
        }

        // Check suppliers
        $dupSupplierNames = DB::select("
            SELECT setting_id, supplier_name, COUNT(*) as count
            FROM suppliers
            WHERE supplier_name IS NOT NULL AND supplier_name != ''
            GROUP BY setting_id, supplier_name
            HAVING COUNT(*) > 1
        ");
        if ($dupSupplierNames) {
            $errors[] = "Duplicate supplier names found: " . json_encode($dupSupplierNames);
        }

        $dupSupplierPhones = DB::select("
            SELECT setting_id, supplier_phone, COUNT(*) as count
            FROM suppliers
            WHERE supplier_phone IS NOT NULL AND supplier_phone != ''
            GROUP BY setting_id, supplier_phone
            HAVING COUNT(*) > 1
        ");
        if ($dupSupplierPhones) {
            $errors[] = "Duplicate supplier phones found: " . json_encode($dupSupplierPhones);
        }

        $dupSupplierEmails = DB::select("
            SELECT setting_id, supplier_email, COUNT(*) as count
            FROM suppliers
            WHERE supplier_email IS NOT NULL AND supplier_email != ''
            GROUP BY setting_id, supplier_email
            HAVING COUNT(*) > 1
        ");
        if ($dupSupplierEmails) {
            $errors[] = "Duplicate supplier emails found: " . json_encode($dupSupplierEmails);
        }

        $dupSupplierIdentities = DB::select("
            SELECT setting_id, identity_number, COUNT(*) as count
            FROM suppliers
            WHERE identity_number IS NOT NULL AND identity_number != ''
            GROUP BY setting_id, identity_number
            HAVING COUNT(*) > 1
        ");
        if ($dupSupplierIdentities) {
            $errors[] = "Duplicate supplier identity numbers found: " . json_encode($dupSupplierIdentities);
        }

        // Check sales references
        $dupSalesRefs = DB::select("
            SELECT setting_id, reference, COUNT(*) as count
            FROM sales
            GROUP BY setting_id, reference
            HAVING COUNT(*) > 1
        ");
        if ($dupSalesRefs) {
            $errors[] = "Duplicate sales references found: " . json_encode($dupSalesRefs);
        }

        // Check sale returns references
        $dupSaleReturnRefs = DB::select("
            SELECT setting_id, reference, COUNT(*) as count
            FROM sale_returns
            GROUP BY setting_id, reference
            HAVING COUNT(*) > 1
        ");
        if ($dupSaleReturnRefs) {
            $errors[] = "Duplicate sale return references found: " . json_encode($dupSaleReturnRefs);
        }

        // Check quotations references (global uniqueness)
        $dupQuotationRefs = DB::select("
            SELECT reference, COUNT(*) as count
            FROM quotations
            GROUP BY reference
            HAVING COUNT(*) > 1
        ");
        if ($dupQuotationRefs) {
            $errors[] = "Duplicate quotation references found: " . json_encode($dupQuotationRefs);
        }

        // Check purchase references
        $dupPurchaseRefs = DB::select("
            SELECT setting_id, reference, COUNT(*) as count
            FROM purchases
            GROUP BY setting_id, reference
            HAVING COUNT(*) > 1
        ");
        if ($dupPurchaseRefs) {
            $errors[] = "Duplicate purchase references found: " . json_encode($dupPurchaseRefs);
        }

        // Check purchase supplier references
        $dupPurchaseSupplierRefs = DB::select("
            SELECT setting_id, supplier_reference_no, COUNT(*) as count
            FROM purchases
            WHERE supplier_reference_no IS NOT NULL AND supplier_reference_no != ''
            GROUP BY setting_id, supplier_reference_no
            HAVING COUNT(*) > 1
        ");
        if ($dupPurchaseSupplierRefs) {
            $errors[] = "Duplicate purchase supplier references found: " . json_encode($dupPurchaseSupplierRefs);
        }

        // Check purchase supplier purchase numbers
        $dupPurchaseNumbers = DB::select("
            SELECT setting_id, supplier_purchase_number, COUNT(*) as count
            FROM purchases
            WHERE supplier_purchase_number IS NOT NULL AND supplier_purchase_number != ''
            GROUP BY setting_id, supplier_purchase_number
            HAVING COUNT(*) > 1
        ");
        if ($dupPurchaseNumbers) {
            $errors[] = "Duplicate purchase supplier purchase numbers found: " . json_encode($dupPurchaseNumbers);
        }

        // Check received notes
        $dupExternalDelivery = DB::select("
            SELECT po_id, external_delivery_number, COUNT(*) as count
            FROM received_notes
            GROUP BY po_id, external_delivery_number
            HAVING COUNT(*) > 1
        ");
        if ($dupExternalDelivery) {
            $errors[] = "Duplicate external delivery numbers found: " . json_encode($dupExternalDelivery);
        }

        $dupInternalInvoice = DB::select("
            SELECT po_id, internal_invoice_number, COUNT(*) as count
            FROM received_notes
            WHERE internal_invoice_number IS NOT NULL AND internal_invoice_number != ''
            GROUP BY po_id, internal_invoice_number
            HAVING COUNT(*) > 1
        ");
        if ($dupInternalInvoice) {
            $errors[] = "Duplicate internal invoice numbers found: " . json_encode($dupInternalInvoice);
        }

        // Check payment methods
        $dupPaymentMethods = DB::select("
            SELECT name, COUNT(*) as count
            FROM payment_methods
            GROUP BY name
            HAVING COUNT(*) > 1
        ");
        if ($dupPaymentMethods) {
            $errors[] = "Duplicate payment methods found: " . json_encode($dupPaymentMethods);
        }

        // Check locations
        $dupLocations = DB::select("
            SELECT setting_id, name, COUNT(*) as count
            FROM locations
            GROUP BY setting_id, name
            HAVING COUNT(*) > 1
        ");
        if ($dupLocations) {
            $errors[] = "Duplicate locations found: " . json_encode($dupLocations);
        }

        // Check settings
        $dupCompanyNames = DB::select("
            SELECT company_name, COUNT(*) as count
            FROM settings
            GROUP BY company_name
            HAVING COUNT(*) > 1
        ");
        if ($dupCompanyNames) {
            $errors[] = "Duplicate company names found: " . json_encode($dupCompanyNames);
        }

        $dupPosPrefixes = DB::select("
            SELECT pos_document_prefix, COUNT(*) as count
            FROM settings
            WHERE pos_document_prefix IS NOT NULL AND pos_document_prefix != ''
            GROUP BY pos_document_prefix
            HAVING COUNT(*) > 1
        ");
        if ($dupPosPrefixes) {
            $errors[] = "Duplicate POS document prefixes found: " . json_encode($dupPosPrefixes);
        }

        if (!empty($errors)) {
            throw new \Exception("Cannot add unique constraints due to existing duplicates:\n" . implode("\n", $errors));
        }
    }
};
