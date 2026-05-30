## 1. Tests And Baseline

- [x] 1.1 Review current purchase report tests in `Modules/Reports/Tests/Feature/PurchaseReportHardeningTest.php` and `Modules/Reports/Tests/Feature/PurchaseReportExportParityTest.php` for header-row assumptions.
- [x] 1.2 Add or update tests that assert default `Tanggal awal` and `Tanggal akhir` use the current calendar month.
- [x] 1.3 Add tests that assert one purchase with multiple `purchase_details` appears as multiple report rows.
- [x] 1.4 Add tests for required Bahasa Indonesia filter labels and absence of the user-facing `Tipe transaksi`, product, and `Gudang` filters.
- [x] 1.5 Add tests for searchable Supplier and Tag filters requiring at least two characters before loading suggestions.
- [x] 1.6 Add tests for multi-select OR behavior for Supplier, Tag, Status Dokumen, and Status Pembayaran.
- [x] 1.7 Add tests that derived payment status ignores invalidated/non-active purchase payments.
- [x] 1.8 Add tests for `Gudang` column display from approved receiving-note locations, including multiple distinct locations.

## 2. Filter Contract

- [x] 2.1 Update `app/Services/Reports/PurchaseReportFilterData.php` to carry supplier IDs, tag IDs, document status values, payment status values, date basis, current-month defaults, and any applied-filter metadata needed by exports.
- [x] 2.2 Update `app/Services/Reports/PurchaseReportValidator.php` to validate multi-select arrays for Supplier, Tag, Status Dokumen, and Status Pembayaran.
- [x] 2.3 Normalize document status and payment status filter values to canonical internal constants while keeping visible labels in Bahasa Indonesia.
- [x] 2.4 Remove validation requirements for user-selected transaction type from the visible report flow.

## 3. Query And Row Mapping

- [x] 3.1 Refactor `app/Services/Reports/PurchaseReportQueryService.php` so the report query is rooted in `Modules\Purchase\Entities\PurchaseDetail`.
- [x] 3.2 Eager-load or join purchase, supplier, tags, product, tax, active payments, and approved receiving-note location data without introducing N+1 queries.
- [x] 3.3 Apply date range filtering to `purchases.date` or `purchases.due_date` based on `Tanggal berdasarkan`.
- [x] 3.4 Apply Supplier and Tag filters with OR semantics within each selected set.
- [x] 3.5 Apply Status Dokumen filters against canonical `purchases.status` values.
- [x] 3.6 Derive Status Pembayaran from active payment totals and apply the selected payment status filters.
- [x] 3.7 Build a reusable row mapping contract for all visible table/export columns, including safe `-` or empty values for unavailable optional fields.
- [x] 3.8 Map `Nomor Transaksi` to `purchase.reference` and `Nomor Pembelian Supplier` to `purchase.supplier_purchase_number`.
- [x] 3.9 Map `Nama Panggilan` and supplier company/contact columns from supplier data according to available fields.
- [x] 3.10 Map `Gudang` to joined distinct approved receiving-note location names for the purchase detail.

## 4. Livewire State And Searchable Filters

- [x] 4.1 Update `app/Livewire/Reports/PurchaseReport.php` defaults so the report initializes to the current month.
- [x] 4.2 Replace single-value document/payment status state with multi-select arrays.
- [x] 4.3 Keep Supplier and Tag selected values as arrays with display labels for selected pills.
- [x] 4.4 Ensure Supplier lookup is server-side, scoped by report mode/setting where applicable, debounced, minimum two characters, limited, and excludes already-selected suppliers.
- [x] 4.5 Ensure Tag lookup is server-side, localized, debounced, minimum two characters, limited, and excludes already-selected tags.
- [x] 4.6 Preserve explicit filtering so changing pending filters does not refresh results until `Filter` is clicked.
- [x] 4.7 Keep cancel/reset behavior consistent with applied filter snapshots.

## 5. Report UI

- [x] 5.1 Update `resources/views/livewire/reports/purchase-report.blade.php` top filters to use Bahasa Indonesia labels and current-month period behavior.
- [x] 5.2 Remove the user-facing `Tipe transaksi` control from the advanced drawer.
- [x] 5.3 Add searchable multi-select Supplier and Tag controls with removable selected pills.
- [x] 5.4 Add multi-select Status Dokumen and Status Pembayaran controls with Bahasa Indonesia labels.
- [x] 5.5 Ensure no Product or `Gudang` filter is rendered.
- [x] 5.6 Replace the header-level table columns with the purchase detail report column set defined in the spec.
- [x] 5.7 Ensure empty state, validation messages, buttons, and placeholders use Bahasa Indonesia.
- [x] 5.8 Keep layout aligned with existing CoreUI/Bootstrap conventions and avoid imported sample CSS classes.

## 6. Export And Print Parity

- [x] 6.1 Decide during implementation whether existing Excel/CSV/PDF controls remain disabled or are updated for detail-row parity; keep behavior explicit in UI. (Decision: exports remain disabled with explicit "belum tersedia" message)
- [x] 6.2 If exports remain available, update `app/Exports/PurchaseReportExport.php` to use the same detail-row query and row mapping contract as the screen. (N/A — exports disabled)
- [x] 6.3 If PDF export remains available, update `resources/views/exports/purchase-pdf.blade.php` to match the detail report columns. (N/A — exports disabled)
- [x] 6.4 Update export parity tests to match the chosen enabled/disabled export behavior.

## 7. Verification

- [x] 7.1 Run focused purchase report tests with `php artisan test --filter=PurchaseReport`.
- [x] 7.2 Run the module report tests affected by this change.
- [x] 7.3 Run `composer test:fresh-sqlite -- --filter=PurchaseReport` if the focused test suite passes and time permits.
- [ ] 7.4 Manually verify `/reports/purchase-report` with current-month defaults, searchable filters, multi-select statuses, and at least one purchase containing multiple detail rows.
