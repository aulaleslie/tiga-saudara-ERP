# Quickstart: Harden Purchase Report Validity

## 1. Run tests for report hardening
```bash
php artisan test --filter=PurchaseReport
php artisan test --filter=PurchaseReportExport
```

## 2. Manual verification flow
1. Open `/reports/purchase-report` as non-global user.
2. Set invalid date range (`end < start`) and click `Tampilkan Laporan`.
3. Confirm validation message appears and report does not run.
4. Set valid filters and click `Tampilkan Laporan`.
5. Confirm rows match filters and scope (`setting_id` boundary).
6. Verify Pajak, Status, and Status Pembayaran dropdowns use `form-control` and look consistent with CoreUI theme.
7. In Supplier search, type 1 character; confirm no lookup is triggered.
8. Type 2+ characters in Supplier and Tag; confirm matching options appear after debounce.
9. Click a suggestion in Supplier/Tag:
   - Confirm suggestion dropdown closes immediately.
   - Confirm search input clears.
   - Confirm a pill appears with the selected item's name.
10. Select multiple suppliers/tags using the pill UI and click `Tampilkan Laporan`.
11. Confirm the results match any of the selected suppliers/tags (`whereIn` logic).
12. Export Excel, CSV, and PDF.
13. Confirm export row counts and transaction identifiers match visible filtered dataset.
14. Change a filter (e.g. remove a pill) without re-running report; attempt export.
15. Confirm export is blocked until `Tampilkan Laporan` is run again.

## 3. Higher-confidence verification
```bash
composer test:fresh-sqlite -- --filter=PurchaseReport
```

## 4. Files expected to change in implementation
- `app/Livewire/Reports/PurchaseReport.php`
- `app/Exports/PurchaseReportExport.php`
- `resources/views/livewire/reports/purchase-report.blade.php`
- `Modules/Reports/Tests/Feature/*` (new/updated)
- `app/Services/Reports/*` (if shared query/validation service is introduced)

## 5. Verification Notes
- All 17 automated tests for report hardening and export parity have passed.
- Filter DTOs, query builders, and validator have been updated to support arrays for `supplierIds` and `tagIds`.
- The Livewire component logic and Blade views have been refactored to support pill-based multi-select, form-control styling, and snapshot validity.
- Export parity and precondition checks verified via tests.
