## Context

The ERP already has an `Expense` module with approval lifecycle, structured `expense_details`, tax handling, setting ownership, archive behavior, and normal-report filtering that includes only approved, non-archived expenses. The Reports module already uses a repeatable pattern for newer reports: module route/controller, Livewire component, filter data object, validator, query service, snapshot service, Blade table, export class, and focused tests.

The provided `report-sample/daftar-pengeluaran` files define a Mekari/Jurnal-style transaction list with the columns `Tanggal`, `Transaksi`, `Nomor`, `Kategori`, `Deskripsi`, `Supplier`, `Jumlah`, `Tax`, `Status`, and `Sisa Tagihan`. The sample also includes supplier filtering, tag filtering with AND/OR behavior, sorting, a detail-mode toggle, and CSV/XLSX/PDF export controls. Current local expenses do not have supplier or tag metadata, so a faithful first version must enrich expense transactions as well as add the report.

## Goals / Non-Goals

**Goals:**
- Add nullable supplier support to expenses without breaking existing rows.
- Add Spatie tag support to expenses and persist tags through normal expense create/edit flows.
- Add an actionable Daftar Pengeluaran report under Pembelian reports using the existing report architecture.
- Match the sample report's business columns, filters, totals, detail toggle, and export formats.
- Preserve current expense lifecycle, setting ownership, archive behavior, and report inclusion rules.
- Keep CSV standards-compliant while preserving sample columns and raw numeric values.

**Non-Goals:**
- Do not introduce partial payment or payable settlement for expenses.
- Do not make supplier mandatory for expenses.
- Do not backfill supplier or tags from free-text descriptions.
- Do not change the existing approval status machine or archive rules.
- Do not reproduce the sample CSV's tab/comma artifact.

## Decisions

### 1. Store expense supplier as nullable `expenses.supplier_id`

Add a nullable foreign key from `expenses.supplier_id` to `suppliers.id`. The supplier belongs to the current setting when selected. Existing expenses remain valid with `NULL` supplier and display `-` in the report.

Alternatives considered:
- Infer supplier from `details` or category text. Rejected because it is not auditable and would make filters unreliable.
- Make supplier required. Rejected because existing expenses and legitimate small cash expenses may not have a supplier.

### 2. Use Spatie tags for expenses

Make `Expense` taggable with the existing tag infrastructure used by report filters. Expense create/edit flows will allow optional tag selection and sync tags through shared persistence so controller and Livewire paths behave consistently.

Alternatives considered:
- Store tags in a JSON column. Rejected because the project already uses Spatie tags and report filters expect normalized tag behavior.
- Report-only tag filter without expense tagging UI. Rejected because users would have no reliable maintenance path for the data.

### 3. Keep expense reporting source-of-truth on approved active expenses

The report query will use approved, non-archived expenses scoped to the current setting. Draft, submitted, rejected, and archived approved expenses remain excluded, preserving `expense-approval-workflow`.

Amounts must account for the current data model:
- `expenses.amount` is stored as an integer through an accessor/mutator, effectively cents-like storage.
- `expense_details.amount` is decimal.
- Header-mode `Jumlah` should use the approved expense total normalized through the model/query layer.
- Detail-mode amounts should come from `expense_details.amount`, with tax calculated consistently with `ExpenseService` and `is_tax_included`.

### 4. Implement report modes explicitly

Summary mode renders one row per expense:

```text
Tanggal | Transaksi | Nomor | Kategori | Deskripsi | Supplier | Jumlah | Tax | Status | Sisa Tagihan
```

Detail mode renders rows per `expense_details` while preserving the same output columns. The `Deskripsi` column becomes the detail row name; `Jumlah` and `Tax` are detail-level values; header fields repeat for readability and export parity.

`Sisa Tagihan` is always `0` for this first version because the current expense domain has no payable settlement lifecycle. `Status` displays the report-facing paid status for included approved expenses, matching the sample's `Paid` value, while normal expense screens may continue using Indonesian lifecycle labels.

### 5. Follow existing report snapshot/export guard pattern

The Livewire report should use filter validation and export snapshots, matching supplier payables and sales/purchase report behavior. Exports should require the current filter state to have been applied before download.

### 6. Produce clean CSV and styled XLSX/PDF exports

CSV should use standard comma-delimited rows, quoted text as needed, UTF-8 handling consistent with existing exports, and raw numeric values. It should not reproduce the sample's data-row tab characters.

XLSX should include heading rows similar to the sample:
- company name
- `Daftar Pengeluaran`
- selected date range
- optional `(dalam IDR)` depending on existing report export convention
- headers at the same logical row as existing report exports
- total row labeled `Total Biaya`

PDF can use the same mapped rows and totals as XLSX/CSV through the existing Maatwebsite DOMPDF export path unless implementation finds an established PDF view pattern that better matches local reports.

### 7. Reuse purchase report permission family

The report is under the Pembelian tab and the existing placeholder is gated by `purchaseReports.access`. The new route/card should use the same permission unless a future permission normalization change introduces a dedicated expense report permission.

## Risks / Trade-offs

- Nullable supplier leaves legacy rows less filterable -> Display `-` and exclude null-supplier rows only when a supplier filter is active.
- Tax calculation can drift from expense persistence -> Centralize or mirror `ExpenseService` tax logic and cover tax-included/tax-excluded cases in tests.
- Spatie tag filters can create expensive `whereHas` queries -> Use the existing AND/OR tag-query patterns from reports and add focused tests; avoid eager-loading large tag collections unless rendering needs them.
- Detail mode can double-count totals if totals are computed from rendered rows incorrectly -> Compute header totals and detail totals deliberately, with tests for multi-detail expenses.
- CSV/XLSX/PDF can diverge -> Use a shared row mapper for table and export values wherever practical, and test export array content.

## Migration Plan

1. Add nullable `supplier_id` to `expenses` with a foreign key to `suppliers.id` using `nullOnDelete` or equivalent safe behavior.
2. Add taggable support to the `Expense` model without requiring data migration for existing rows.
3. Existing expenses keep `supplier_id = NULL` and no tags.
4. Rollback removes the nullable supplier column and leaves Spatie tag tables intact; any taggable rows for expenses become harmless orphaned metadata only if the rollback path does not explicitly delete them.

## Open Questions

- Should the visible status text be exactly `Paid` for sample parity or localized as `Lunas`? This design assumes `Paid` in the report/export to match the provided sample.
- Should the report's "Tax" column display `0` for non-PKP settings even when detail tax IDs exist from older data? This design assumes current expense tax rules remain authoritative.
