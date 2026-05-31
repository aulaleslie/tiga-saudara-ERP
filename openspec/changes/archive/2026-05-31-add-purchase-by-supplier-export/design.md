## Context

The `Pembelian Per Supplier` report (`reports.purchase-by-supplier.index`) is a Livewire 3 component (`App\Livewire\Reports\PurchaseBySupplierReport`) backed by `PurchaseBySupplierReportQueryService`. It already supports full filter/sort and paginated display. The parallel `Laporan Pembelian` report has a mature export pattern using `maatwebsite/excel` with a session snapshot guard — that pattern is the direct reference for this implementation.

**Current gaps before export can be added:**
1. `PurchaseBySupplierReportFilterData` has no `hash()`, `toArray()`, or `fromArray()` — needed by the snapshot guard.
2. `PurchaseBySupplierReportQueryService::mapRow()` returns a combined `Supplier / Tanggal` key — the export requires them split.
3. No snapshot service or snapshot DTO exists for this report.
4. No export class exists.
5. The Livewire component has no `exportExcel()` / `exportCsv()` methods.
6. The blade view has no export dropdown.

## Goals / Non-Goals

**Goals:**
- Add XLSX and CSV download via Livewire actions, gated by a session snapshot guard.
- Export uses the same filters and sort already applied when the user last clicked `Filter`.
- Export columns: 11 columns — `Supplier`, `Tanggal`, `Tipe transaksi`, `No. transaksi`, `Nama produk`, `Keterangan`, `Qty`, `Unit`, `Harga per unit`, `Nominal tagihan`, `Total nominal tagihan` — matching the reference sample file.
- XLSX gets a merged title row and period row above the headers (AfterSheet event), mirroring `PurchaseReportExport`.
- Running totals computed in PHP during mapping (stateful array keyed by `supplier_id`).
- Snapshot guard: export blocked if `filterTriggered` is false or applied filter hash doesn't match the stored snapshot.
- Update the `purchase-by-supplier-report` spec to replace the "no export" requirement with an active export requirement.
- Add a `purchase-by-supplier-report-export` spec covering export-specific requirements.

**Non-Goals:**
- PDF export (stub or omit entirely — not in scope).
- Transaction type filtering beyond `Faktur pembelian` (query service already scopes to Purchase model).
- Changing pagination behaviour or existing UI layout beyond adding the export dropdown.

## Decisions

### D1: Split `mapRow()` — add `mapRowForExport()` rather than changing `mapRow()`

`mapRow()` keys drive the blade template (e.g., `$mapped['Supplier / Tanggal']`). Changing its keys would break the existing view. Instead, add a static `mapRowForExport(PurchaseDetail $detail, float $runningTotal): array` that returns the 11-column export shape with split `Supplier` and `Tanggal` columns.

**Alternative considered**: a `$forExport` flag parameter on `mapRow()`. Rejected — boolean flags obscure intent and complicate future divergence.

### D2: Running totals via stateful PHP array in the export class

The export class holds `private array $runningTotals = []`. `map($row)` accumulates `$row->sub_total` keyed by `$row->supplier_id`. The query is already sorted so all rows for a supplier are contiguous — the running total resets naturally when a new supplier ID is encountered (it starts at 0 via `??= 0`).

**Alternative considered**: SQL window function `SUM(sub_total) OVER (PARTITION BY supplier_id ORDER BY ...)`. Rejected — tightly couples sort logic into SQL and is harder to test independently.

### D3: Snapshot guard modelled exactly on `PurchaseReportSnapshotService`

`PurchaseBySupplierReportSnapshot` stores `snapshotKey`, `validatedFilterHash`, `generatedAt`, `actorUserId`, `scopeSettingId`, `resultCount`. No `isGlobal` or `dateBasis` fields (not applicable here). `PurchaseBySupplierReportFilterData` gains `hash()` (md5 of serialized `toArray()`), `toArray()`, and `fromArray()`.

The snapshot is created inside `applyFilters()` after successful validation, and checked at the start of both export methods.

### D4: Export class structure mirrors `PurchaseReportExport`

`PurchaseBySupplierReportExport implements FromQuery, WithHeadings, WithMapping, WithEvents`. Constructor receives `Builder $query`, `PurchaseBySupplierReportFilterData $filterData`, `bool $isCsv`. `WithEvents::registerEvents()` returns `[]` when `$isCsv` is true, skipping the AfterSheet formatting.

### D5: Filename pattern matches the reference sample

```
purchases_by_vendor_{dd-mm-yyyy}_{dd-mm-yyyy}.xlsx
purchases_by_vendor_{dd-mm-yyyy}_{dd-mm-yyyy}.csv
```

### D6: Export button UI — dropdown matching `purchase-report` blade pattern

A split button group with `Ekspor` label and dropdown items `Excel` and `CSV`. `PDF` item omitted entirely (not stubbed as disabled, since PDF is not planned). The button is always rendered but both actions self-guard (show alert if `filterTriggered` is false).

## Risks / Trade-offs

- **Large dataset memory**: Running totals via PHP accumulation keeps all supplier IDs in `$runningTotals` for the export duration. For typical ERP dataset sizes this is negligible. For very large exports, `maatwebsite/excel` streams via `FromQuery` so rows are not all loaded into memory simultaneously — only the running total accumulator grows.
- **Sort determinism**: Running totals are only meaningful if all rows for a supplier are contiguous. The existing `applySort()` already guarantees this via the supplier-id tie-breaker. The export reuses the same `applySort()` call, so the invariant holds.
- **Snapshot staleness on concurrent tabs**: If a user opens the report in two tabs and applies different filters in each, the session snapshot reflects only the latest `applyFilters()` call. This matches the existing `PurchaseReport` behaviour and is acceptable for a session-scoped report.

## Migration Plan

No database migrations. No background jobs. Deploy is a standard code push. Rollback: revert the changed files — no persistent state is introduced beyond the session snapshot (which expires naturally with the session).
