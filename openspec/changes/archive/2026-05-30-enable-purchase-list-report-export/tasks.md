
## 1. Table Parity
- [x] 1.1 Verify and update table column mapping parity
- [x] 1.2 Implement precise naming for mapping keys (e.g. `Sisa Tagihan Hari Ini`)
- [x] 1.3 Ensure valid numeric calculations with Excel formulas
- [x] 1.4 Empty data defaults to `-` instead of 0 or blank

## 2. Refactoring Export Logic
- [x] 2.1 Refactor `PurchaseReportExport` to implement `FromQuery`
- [x] 2.2 Rebuild `headings()` using manual defined string array matching Table UI
- [x] 2.3 Ensure CSV exports cleanly matching the raw rows without meta rows
- [x] 2.4 Extract logic to `mapRow(PurchaseDetail $row): array`
- [x] 2.5 Ensure the Livewire view uses the exact same mapping output

## 3. UI and Validation
- [x] 3.1 Enable the Excel export button
- [x] 3.2 Enable the CSV export button
- [x] 3.3 Prevent PDF generation while keeping the button disabled
- [x] 3.4 Ensure pending filter changes that have not been applied do not affect exported rows
- [x] 3.5 Validate filters match UI before proceeding with export

## 4. Testing & Verification
- [x] 4.1 Verify missing `PurchaseReportExport` dependencies
- [x] 4.2 Prevent file load issues with empty exports
- [x] 4.3 Add snapshot validation when testing `PurchaseReportExport`
- [x] 4.4 Ensure sort parameter application parity
- [x] 4.5 Check missing relationships handling inside mapping
- [x] 4.6 Test empty default fallback handling
- [x] 4.7 Map tests directly to Livewire action methods
- [x] 4.8 Extend test coverage specifically around edge cases
- [x] 4.9 Run focused verification with `php artisan test --filter=PurchaseReport`
