## 1. Test Coverage

- [x] 1.1 Add focused purchase list search tests covering `supplier_purchase_number`, `tax_ref_no`, `supplier_reference_no`, purchase detail `product_name`, and purchase detail `product_code`.
- [x] 1.2 Add focused sales list search tests covering `imported_sales_reference_number`, `tax_ref_no`, sale detail `product_name`, and sale detail `product_code`.
- [x] 1.3 Add focused purchase return list search tests covering return `reference`, supplier name, return detail `product_name`, return detail `product_code`, and linked source purchase identifiers.
- [x] 1.4 Add focused sale return list search tests covering return `reference`, customer name, return detail `product_name`, return detail `product_code`, and linked source sale identifiers.
- [x] 1.5 Add coverage or assertions that searches matching multiple detail rows return one document row and compose with existing filters/sorting where the list supports them.

## 2. Livewire List Search

- [x] 2.1 Update `app/Livewire/Purchase/PurchaseTable.php` search predicates to match purchase detail snapshot `product_name` and `product_code`, plus `supplier_reference_no`, while preserving existing search fields.
- [x] 2.2 Update `app/Livewire/Sale/SaleTable.php` search predicates to match sale detail snapshot `product_name` and `product_code`, while preserving existing search fields.
- [x] 2.3 Review purchase and sales table placeholders/tooltips so they accurately describe the expanded searchable fields without adding a new search UI.

## 3. Return DataTable Search

- [x] 3.1 Add explicit global search handling to `Modules/PurchasesReturn/DataTables/PurchaseReturnsDataTable.php` for return reference, supplier names, return detail product snapshots, and linked source purchase `reference`, `supplier_purchase_number`, `supplier_reference_no`, and `tax_ref_no`.
- [x] 3.2 Add explicit global search handling to `Modules/SalesReturn/DataTables/SaleReturnsDataTable.php` for return reference, customer names, sale reference, return detail product snapshots, and linked source sale `reference`, `imported_sales_reference_number`, and `tax_ref_no`.
- [x] 3.3 Ensure computed/action/status columns in the return DataTables do not introduce duplicate rows or broken DataTables search/count behavior.

## 4. Verification

- [x] 4.1 Run the focused tests for purchase, sales, purchase return, and sale return list search.
- [x] 4.2 Run a broader focused Laravel test command if needed to cover affected list components and DataTables behavior.
- [x] 4.3 Manually review the four list pages or rendered responses to confirm search, pagination, sort, and existing filters still compose correctly.
