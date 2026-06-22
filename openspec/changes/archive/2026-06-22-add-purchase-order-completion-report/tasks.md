## 1. Report Shell and Navigation

- [x] 1.1 Add `PurchaseOrderCompletionReportController`, module index view, and `reports.purchase-order-completion.index` route guarded by `purchaseReports.access`
- [x] 1.2 Register a `PurchaseOrderCompletionReport` Livewire component and mount it from the module index view
- [x] 1.3 Update the Reports landing Pembelian card so `Penyelesaian pesanan pembelian` links to the new route and is no longer placeholder-treated
- [x] 1.4 Add routing/navigation tests for authorized access, unauthorized 403, and the actionable landing card

## 2. Filter and Snapshot Foundation

- [x] 2.1 Create `PurchaseOrderCompletionReportFilterData` with date range, source stage, supplier ids, tag ids, tag logic, setting scope, and hash support
- [x] 2.2 Create `PurchaseOrderCompletionReportValidator` with Bahasa Indonesia validation messages for date range, source stage, supplier ids, tag ids, and tag logic
- [x] 2.3 Create `PurchaseOrderCompletionReportSnapshot` and `PurchaseOrderCompletionReportSnapshotService` for applied-filter export protection
- [x] 2.4 Add unit or feature coverage for filter normalization, validation failures, and snapshot hash matching/mismatch

## 3. Query and Mapping

- [x] 3.1 Create `PurchaseOrderCompletionReportQueryService` that builds one row per purchase scoped to the active setting and filtered by `purchases.date`
- [x] 3.2 Implement source-stage filtering for `Penawaran` and `Pemesanan` using canonical purchase statuses
- [x] 3.3 Implement supplier and purchase tag filters, including `Salah satu` and `Mencakup semua` tag logic
- [x] 3.4 Implement derived receiving amount from approved receiving notes using proportional purchase detail valuation with zero-quantity guards
- [x] 3.5 Implement derived invoice amount and effective payment amount using active purchase payments with existing purchase-report fallback semantics
- [x] 3.6 Implement row headings, row mapping, Indonesian date formatting, completion status labels, deterministic sorting, and safe empty/fallback values
- [x] 3.7 Add focused query-service tests for date filtering, tenant scoping, source-stage filtering, receiving amount, payment fallback, invalidated payments, status labels, and sorting

## 4. Livewire UI

- [x] 4.1 Build the Livewire component state and actions for period presets, supplier search/select/remove, tag search/select/remove, tag logic, source stage, sorting, reset/cancel, apply filters, and pagination
- [x] 4.2 Build the Blade report UI with quick filters, advanced filter drawer, export controls, initial prompt, empty state, table, totals row, and Indonesian IDR amount formatting
- [x] 4.3 Ensure export actions are blocked before filters are applied and after pending filter changes
- [x] 4.4 Add Livewire tests for filter application, pending-filter cancellation/reset behavior, empty state, totals display, and export guard alerts

## 5. Export

- [x] 5.1 Create `PurchaseOrderCompletionReportExport` for XLSX and CSV using all rows from the applied query, not the current page
- [x] 5.2 Add XLSX metadata rows for company name, `purchase_order_completion`, selected date range, and `(dalam IDR)`, plus a total row when data exists
- [x] 5.3 Format CSV as table-only output without metadata or total row, and emit monetary numeric values with two decimals to avoid floating precision artifacts
- [x] 5.4 Add export tests for headings, data mapping, XLSX collection total row behavior, CSV numeric formatting, and snapshot-validated export blocking

## 6. Regression and Verification

- [x] 6.1 Run focused tests for the new purchase order completion report
- [x] 6.2 Run related report tests for sales order completion, purchase delivery, purchase list, and reports landing navigation
- [x] 6.3 Run `openspec validate add-purchase-order-completion-report --strict`
- [x] 6.4 Document any intentionally deferred scope, including PDF export, detail template, global cross-setting version, and database migrations
