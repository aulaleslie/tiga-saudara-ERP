## Why

The current `Daftar Pembelian` report is header-oriented and does not match the imported purchase report sample users want to use for operational review. Production use also needs scalable searchable filters because supplier and tag datasets can grow into the thousands.

## What Changes

- Refine `/reports/purchase-report` into a purchase invoice detail report for `Faktur Pembelian`, with one result row per purchase detail/product line.
- Keep all visible report labels and filter wording in Bahasa Indonesia.
- Default the report period to the current month.
- Remove the user-facing transaction type filter because this report only covers `Faktur Pembelian`.
- Keep the sample-style report columns and add `Nomor Pembelian Supplier` next to `Nomor Transaksi`.
- Preserve the `Gudang` column for sample parity, displaying receiving location names when available, but do not add a `Gudang`/location filter.
- Provide scalable searchable multi-select filters for `Supplier` and `Grup dengan tag`; tag matching uses OR behavior.
- Add separate multi-select filters for `Status Dokumen` and `Status Pembayaran`.
- Derive payment status from active purchase payment transactions rather than trusting stale purchase header payment fields.
- Keep existing route and permission behavior unless implementation discovers a direct conflict.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `purchase-list-report`: Change the purchase list report from a header-level report into a purchase invoice detail report with sample-aligned columns, current-month default period, Bahasa Indonesia labels, searchable multi-select Supplier/Tag filters, and separate multi-select document/payment status filters.

## Impact

- Affected report UI:
  - `resources/views/livewire/reports/purchase-report.blade.php`
  - `Modules/Reports/Resources/views/purchase-report/index.blade.php`
- Affected Livewire/backend report flow:
  - `app/Livewire/Reports/PurchaseReport.php`
  - `app/Services/Reports/PurchaseReportFilterData.php`
  - `app/Services/Reports/PurchaseReportValidator.php`
  - `app/Services/Reports/PurchaseReportQueryService.php`
- Affected exports, if export parity is kept with the visible report:
  - `app/Exports/PurchaseReportExport.php`
  - `resources/views/exports/purchase-pdf.blade.php`
- Affected domain entities and relationships:
  - `Modules/Purchase/Entities/Purchase.php`
  - `Modules/Purchase/Entities/PurchaseDetail.php`
  - `Modules/Purchase/Entities/PurchasePayment.php`
  - `Modules/Purchase/Entities/ReceivedNote.php`
  - `Modules/Purchase/Entities/ReceivedNoteDetail.php`
  - `Modules/People/Entities/Supplier.php`
  - `Modules/Product/Entities/Product.php`
- Affected tests:
  - Purchase report feature/Livewire tests under `Modules/Reports/Tests/Feature/`
- No database schema changes are expected.
- No new permission is expected.
