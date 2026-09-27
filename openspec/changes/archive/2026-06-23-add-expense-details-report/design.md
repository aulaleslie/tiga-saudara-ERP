## Context

The ERP already has a working expense domain and a newer reports architecture. Expenses belong to the active setting, have an approval/archive lifecycle, reference one `expense_categories` row through `expenses.category_id`, keep a legacy `expenses.details` summary, and also persist structured rows in `expense_details`. The existing `Daftar Pengeluaran` report uses the current report pattern: route/controller, Livewire component, filter data object, validator, query service, snapshot guard, export class, Blade table, and focused tests.

The `Detail pengeluaran` card is currently a placeholder in the Pembelian tab. The provided `report-sample/detail-pengeluaran` and `report-sample/rincian-biaya` files define a Mekari/Jurnal-style `Rincian Biaya` report:

- UI title: `Rincian Biaya` with `(dalam IDR)`.
- Columns: `Kategori / Tanggal`, `Transaksi`, `Nomor`, `Keterangan`, `Jumlah`.
- UI and XLSX group transaction rows under account/category headers and include subtotals and a grand total.
- CSV exports only flat transaction rows and omits metadata rows, group headers, subtotals, and grand total.
- XLSX includes company name, report title, date range, currency note, grouped rows, category subtotals, and final label `Grand Total Biaya`.

The sample uses account links (`/accounts/:id`), but the local expense schema does not connect expenses to `chart_of_accounts`. Existing operational ledger normalization already treats expense category names as the operational expense grouping context, so category grouping is the brownfield-compatible mapping.

## Goals / Non-Goals

**Goals:**

- Add an actionable `Detail pengeluaran` report page under Pembelian reports using `purchaseReports.access`.
- Match the sample's visible report shape while adapting source data to the local expense model.
- Include only approved, non-archived expenses for the current `setting_id`.
- Group report rows by `expense_categories.category_name`.
- Render one transaction row per expense, not one row per structured `expense_details` item.
- Use `expenses.details` as the `Keterangan` value.
- Support date range, category filter, tag filter with AND/OR behavior, and deterministic sort direction.
- Add snapshot-guarded CSV, XLSX, and PDF exports with format-specific structures.
- Keep behavior separate from `Daftar Pengeluaran` and preserve that report unchanged.

**Non-Goals:**

- Do not add `account_id` or `coa_id` to expenses in this change.
- Do not implement full chart-of-account drill-down matching Mekari `/accounts/:id` links.
- Do not add a detail-row expansion toggle; `Daftar Pengeluaran` already owns that pattern.
- Do not change expense creation/edit approval behavior, supplier support, or tag persistence.
- Do not reproduce the sample CSV's tab/comma artifact; generate standards-compliant CSV.

## Decisions

### 1. Implement a separate Expense Details report

Create new report-specific classes/files instead of extending `ExpenseListReport` detail mode. The existing expense list report has ten columns, supplier/status/outstanding semantics, and an optional detail-row expansion. `Rincian Biaya` is a distinct five-column category-grouped report with subtotals and format-specific export structures.

Alternatives considered:
- Add a new mode to `ExpenseListReport`. Rejected because it would mix two report contracts and complicate exports.
- Reuse only the `ExpenseListReportQueryService` output. Rejected because it maps different columns and totals.

### 2. Use expense categories as account/category groups

Group by `expenses.category_id` and display `expense_categories.category_name` as the group header. This is the closest local equivalent to the sample's account grouping because expenses do not reference chart of accounts. It also matches existing operational ledger code, which tags expense cost movements with the expense category name.

Alternatives considered:
- Group by `chart_of_accounts`. Rejected because there is no expense-to-COA relationship and adding one would be a separate data model change.
- Group by `expense_details.name`. Rejected because the sample groups by account/category, not by individual line description.

### 3. Render one row per expense

Each matching approved expense produces one transaction row. `Keterangan` uses `expenses.details`, which the expense service maintains as a comma-separated summary of structured detail row names. `Jumlah` uses the normalized expense total through the model accessor/query layer.

Alternatives considered:
- Expand rows per `expense_details`. Rejected for this report because it would change the sample's transaction-count model and could split one expense into multiple report rows.
- Leave `Keterangan` blank to match the provided samples. Rejected because the local system has meaningful detail summaries and the column should be useful when available.

### 4. Preserve format-specific export behavior

CSV output contains only the five column headers and flat transaction rows. XLSX and PDF output include the company/title/date/currency header block, category group headers, category subtotals, and final total. UI uses `Grand Total`; XLSX/PDF use `Grand Total Biaya` for sample parity.

Alternatives considered:
- Make all export formats include group headers and totals. Rejected because both CSV samples omit them.
- Make XLSX use `Grand Total` to match the UI. Rejected because both XLSX samples use `Grand Total Biaya`.

### 5. Reuse report snapshot guard and filter conventions

Use the same filter validation and export snapshot pattern as newer reports. Export must require a previously applied filter state and reject stale exports after filter changes.

Alternatives considered:
- Allow direct export from current component state. Rejected because existing report hardening patterns require explicit applied filters for predictable exports.

## Risks / Trade-offs

- Expense category is not a real COA account -> Document and test the category mapping; avoid rendering account drill-down links until a true mapping exists.
- `expenses.details` can be long for multi-line expenses -> Render safely with normal table wrapping and export as plain text.
- Grouped totals can drift from rendered rows if computed separately -> Build a single row/group mapper used by UI and grouped exports.
- CSV and UI intentionally differ -> Cover CSV shape in tests so future refactors do not add subtotal rows accidentally.
- Large date ranges can load many expenses for grouped totals/export -> Use eager loading for category/tags, deterministic query sorting, and keep pagination for UI while exports stream/download through the export path.

## Migration Plan

No database migration is required.

Deployment steps:
1. Add report service/filter/validator/snapshot/export classes and Livewire component.
2. Add the report route, controller, module view, and Livewire Blade table.
3. Convert the `Detail pengeluaran` report card from placeholder to actionable route.
4. Add focused tests for access, filters, grouping, totals, exports, and landing navigation.

Rollback:
- Remove the new route/controller/view/component/service/export/test files.
- Restore the landing card to placeholder if the feature must be disabled.
- No data rollback is needed.

## Open Questions

None currently. The proposal intentionally maps sample account grouping to local expense categories because the current expense schema has no chart-of-account relationship.
