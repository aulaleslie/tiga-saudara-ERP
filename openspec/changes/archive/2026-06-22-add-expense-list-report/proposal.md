## Why

The reports landing page currently lists "Daftar pengeluaran" as a disabled purchase-report placeholder, while expense data already participates in financial reporting and needs a transaction-level operational report matching the provided Mekari/Jurnal sample. To support that report with useful filtering, expenses also need first-class optional supplier and tag metadata.

## What Changes

- Add optional supplier assignment to expense transactions so new and edited expenses can be associated with a supplier while legacy expenses remain valid with no supplier.
- Add tag support to expense transactions, including create/edit persistence, show/list visibility where appropriate, and current-setting-safe reporting filters.
- Add an actionable "Daftar pengeluaran" report under the Pembelian reports tab, gated by `purchaseReports.access`.
- Add a Livewire expense list report with date-range filtering, supplier filtering, tag filtering with AND/OR logic, sortable columns, summary mode, and "Perlihatkan Lebih Detail" detail mode.
- Add CSV, XLSX, and PDF export support for the report, keeping the sample's columns and values while using a clean standards-compliant CSV instead of reproducing the sample's mixed tab/comma artifact.
- Preserve existing expense lifecycle and reporting rules: normal reports include only approved, non-archived expenses in the current setting.

## Capabilities

### New Capabilities
- `expense-list-report`: Defines the Daftar Pengeluaran report, its filters, summary/detail modes, totals, exports, permission gate, and current-setting behavior.

### Modified Capabilities
- `expense-approval-workflow`: Adds optional supplier and tags to expense transactions and requires shared persistence, validation, visibility, and setting ownership behavior across expense write/read paths.
- `reports-landing-navigation`: Replaces the disabled Daftar pengeluaran placeholder with an actionable report card routed to the new report.

## Impact

- Affected source: `Modules/Expense`, `app/Livewire/Expense`, `resources/views/livewire/expense`, `Modules/Reports`, `app/Livewire/Reports`, `app/Services/Reports`, `app/Exports`, report routes/controllers/views, permission-gated landing card configuration, and tests.
- Database impact: additive nullable `expenses.supplier_id` relationship and Spatie taggable associations for expenses; no destructive migration and no required supplier backfill for existing expenses.
- Export impact: new report export classes/files for XLSX, CSV, and PDF with explicit column and total parity.
- Test impact: focused feature, Livewire, query-service, export, landing navigation, and migration/default-legacy behavior coverage.
