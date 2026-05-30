## Context

`Daftar Pembelian` is served by the existing `/reports/purchase-report` route and `App\Livewire\Reports\PurchaseReport`. The current report state is split between pending filter properties and `appliedFilters`, with `PurchaseReportSnapshotService` recording the last validated filter hash when the user clicks `Filter`.

The report table is already detail-line based. `PurchaseReportQueryService::build()` returns `PurchaseDetail` rows joined to their parent purchases, suppliers, active payment totals, and approved receiving locations. The table maps each row through `PurchaseReportQueryService::mapRow()`, which is the current display column contract. The existing `App\Exports\PurchaseReportExport` is stale because it assumes purchase header rows and a much smaller column set.

## Goals / Non-Goals

**Goals:**

- Enable Excel and CSV export for the existing purchase list report.
- Export the same logical data contract as the current table: purchase detail rows and current table columns.
- Export all rows matching the last applied filters, not the current pagination page.
- Respect the current table sort at export time.
- Keep exports tied to the last validated/applied filter snapshot so pending drawer edits do not leak into exported files.
- Use raw spreadsheet-friendly numeric values for amount, quantity, and percentage columns.
- Keep empty optional values as `-`.
- Add XLSX metadata rows above the table and keep CSV as a plain header/data file.
- Keep sample-style filenames.

**Non-Goals:**

- No PDF export.
- No route, permission, navigation, or schema changes.
- No new report template selector.
- No change to the report's filtering semantics or table column set.
- No attempt to make the export match the external sample files when they differ from the current ERP table.

## Decisions

### Decision 1: Reuse the report query and row mapper

Export should use `PurchaseReportQueryService::build()` and the same row mapping source as the table rather than creating a separate export query.

Rationale:
- It keeps filters, setting scope, derived payment status, tag matching, supplier matching, and receiving location logic consistent.
- It avoids reviving the stale header-level assumptions in the current `PurchaseReportExport`.
- The table already centralizes its data shape through `mapRow()`.

Alternatives considered:
- Build a separate export query directly from `purchases`: rejected because the report is now detail-line based and would lose product columns.
- Copy Blade formatting into the export: rejected because UI formatting and spreadsheet export values have different needs.

### Decision 2: Separate export value normalization from table presentation

The export should use the table column order and labels, but normalize values for spreadsheet use. Numeric amounts, quantities, and percentages should be exported as raw numbers. Optional empty values should remain `-`.

Rationale:
- Users chose current table columns as the export contract, but raw numeric values are better for Excel/CSV calculations.
- Keeping normalization close to the export prevents Blade display formatting such as localized number strings or percent suffixes from leaking into CSV.

Alternatives considered:
- Export exactly what the UI displays: rejected because values like `1.065.000` and `11%` are less useful for calculations and sorting.
- Export sample-file columns exactly: rejected because the accepted decision was current table parity.

### Decision 3: Export from the last applied filter snapshot

Excel and CSV actions should require a valid latest snapshot for the current `appliedFilters`. If no report has been run, or the current export filter state does not match the latest snapshot, the component should block export with the existing alert pattern.

Rationale:
- The report intentionally separates pending filter inputs from applied results.
- Export should correspond to what the user intentionally filtered, not half-edited drawer state.
- The snapshot service already exists for this guard.

Alternatives considered:
- Automatically run filters on export: rejected because it changes the explicit `Filter` interaction model.
- Export from current public properties directly: rejected because pending drawer values could diverge from visible results.

### Decision 4: Apply current sort to unpaginated export query

The export should reuse the current sort field and direction, then apply the same stable tie-breakers used by the table. It should not call `paginate()`.

Rationale:
- Users expect the file to follow the visible ordering choice.
- Exporting all filtered rows is standard report behavior.
- Stable tie-breakers make repeated exports deterministic.

Alternatives considered:
- Always use default date order: rejected because it ignores user-selected table sort.
- Export current page only: rejected because reports are expected to produce complete filtered datasets.

### Decision 5: Keep one export class or adapter aligned to both file types

One export implementation should provide the shared headings and row mapping for Excel and CSV. XLSX-specific metadata rows can be added through events or concern-specific handling, while CSV remains plain headers plus rows.

Rationale:
- Excel and CSV should not drift in columns or row values.
- Maatwebsite Excel is already installed and used by existing report exports.
- CSV should avoid extra metadata rows so downstream spreadsheet/import workflows can consume it directly.

Alternatives considered:
- Separate Excel and CSV classes: viable, but higher drift risk unless they share a row adapter.
- Manual streamed CSV plus Maatwebsite XLSX: viable, but unnecessary unless memory/performance problems appear.

## Risks / Trade-offs

- **Large filtered exports may consume memory** -> Prefer a query-backed export or chunking concern if practical; avoid paginating but keep the implementation compatible with large result sets.
- **The existing export class is misleading** -> Replace or heavily refactor it so it no longer maps `PurchaseDetail` rows as if they were `Purchase` headers.
- **Sort logic duplication can drift from render logic** -> Extract or centralize sort application so table render and export use the same allowed sort fields and tie-breakers.
- **CSV and XLSX metadata needs differ** -> Keep metadata out of CSV and explicitly test that CSV begins with column headers.
- **PDF remains visible by accident** -> Update the dropdown and tests so PDF is disabled or hidden while Excel and CSV are actionable.
