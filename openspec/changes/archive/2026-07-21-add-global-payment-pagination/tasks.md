## 1. Views and UI Updates

- [x] 1.1 Add `supplier_purchase_number` header to the invoice table in `Modules/Purchase/Resources/views/payments/global-create.blade.php`.
- [x] 1.2 Add `supplier_purchase_number` cell to the invoice table rows.
- [x] 1.3 Initialize DataTables on the invoice table with client-side pagination and `lengthMenu` set to `[[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]]`.

## 2. Form Submission Logic Updates

- [x] 2.1 Update the form submit script (`#payment-form`) to extract values from all pages by using DataTables API: `var table = $('#allocations-table').DataTable();` and iterating over `table.$('.allocation-input')`.
- [x] 2.2 Test that allocations on non-visible pages are correctly submitted to the server.
- [x] 2.3 Verify `jquery-mask-money` still correctly formats and parses values for all rows when pagination is active.
