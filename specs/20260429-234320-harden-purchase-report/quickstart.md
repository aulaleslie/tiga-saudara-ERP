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
6. In Supplier search, type 1 character; confirm no lookup is triggered.
7. Type 2+ characters in Supplier and Tag; confirm matching options appear after debounce and controls remain responsive.
8. Export Excel, CSV, and PDF.
9. Confirm export row counts and transaction identifiers match visible filtered dataset.
10. Change a filter without re-running report; attempt export.
11. Confirm export is blocked until `Tampilkan Laporan` is run again.

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
