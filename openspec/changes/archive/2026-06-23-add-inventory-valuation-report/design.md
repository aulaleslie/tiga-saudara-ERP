## Context

The reports suite already ships an **Inventory Summary** report (`App\Services\Reports\InventorySummaryReportQueryService` + `App\Livewire\Reports\InventorySummaryReport` + `Modules\Reports` controller/route/view). That service replays each product's inventory `Transaction` rows in chronological order to produce a single as-of snapshot: final running stock, final weighted-average cost (WAC), and value. It already solves the hard problems — transaction sort order, signed delta resolution (`resolveDelta`), per-reference purchase/sale unit-price resolution (`resolveUnitPrice`, `buildPurchasePriceMap`, `buildSalePriceMap`), transaction date resolution across purchases/sales/transfers, and the WAC recurrence (`applyTransaction`).

The new **Nilai Persediaan Barang** (Inventory Valuation) report is the *ledger* form of that same computation: instead of emitting only the final snapshot, it emits a row at each replay step, over a date **range**, prefixed with a "Saldo Awal" opening-balance row, grouped per product with subtotals and a grand total. The reference design comes from Mekari Jurnal (captured under `report-sample/nilai-persediaan-barang/`).

Constraints: Laravel 10 + Livewire 3, nwidart modules under `Modules/`, multi-tenant by `setting_id`, `maatwebsite/excel` for exports, Bootstrap pagination. Read-only; no schema changes.

## Goals / Non-Goals

**Goals:**
- A per-product, per-transaction valuation ledger over a date range with running stock, running WAC, and running value.
- A "Saldo Awal" opening row per product reflecting all activity strictly before the start date.
- Per-product subtotals ("Total stok di gudang", "Subtotal nilai") and a grand "Total nilai" across all pages.
- Category (all/any) + product + date-range/period filters matching the Inventory Summary report's UX.
- CSV + XLSX export of the full result set (all pages), including subtotals and grand total, in the reference column order.
- Maximize reuse of the existing replay engine so both reports stay numerically consistent.

**Non-Goals:**
- Per-warehouse valuation. Valuation is **company-wide** (single running WAC per product), matching the sample CSV which has no warehouse column.
- Changing the WAC method or the existing Inventory Summary report's output.
- Real-time/streaming updates; the report computes on demand per applied filters.
- New permissions; the report reuses `inventoryValuationReports.access`.

## Decisions

### Decision: Reuse the existing replay engine, emit a row per step

The replay loop in `InventorySummaryReportQueryService::getSummary` already carries `$runningStock` and `$runningAvg` through each transaction. The valuation report needs exactly that loop, but emitting a ledger row after each `applyTransaction` call rather than only the final values.

**Approach:** Extract the shared mechanics (product loading + category/product filtering, transaction loading + sort, price/date maps, `resolveDelta`, `resolveUnitPrice`, `applyTransaction`, `extractReference`) into a reusable place, then build `InventoryValuationReportQueryService` on top of it. Two viable shapes:
- **A (preferred):** Extract a small shared trait/base (e.g. `InventoryReplaySupport`) holding the price/date map builders and the delta/price/WAC helpers; both services use it. Keeps each service's public shape independent.
- **B:** Have the valuation service depend on a refactored summary service that exposes a per-step callback.

Prefer **A** — lower coupling, no behavior change to the shipped summary report, and the helpers are already private/pure. Confirm during implementation that extraction does not alter Inventory Summary output (covered by its existing test).

*Alternative considered — fully independent duplicate service:* rejected. It would fork the WAC/price/date logic into two copies that drift, which is exactly the maintenance risk this report introduces.

### Decision: Opening balance via pre-range replay

The "Saldo Awal" row is the running stock + WAC produced by replaying every transaction dated strictly before `tanggalAwal`, with no row emitted for those transactions. The replay then continues into the range, emitting rows. This reuses the same loop with a date guard: `date < start` → fold silently into running state; `start <= date <= end` → fold and emit; `date > end` → stop.

*Alternative — store/snapshot opening balances:* rejected; no such snapshot table exists and computing on the fly matches how Inventory Summary already derives as-of state.

### Decision: Group-aware pagination by product

To keep a product's opening row, ledger rows, and subtotal together, paginate by **product group**, not by row. The service computes the full grouped result, sorts products (by name default), then `forPage`s over the *products* and flattens each page's groups into display rows. `totalValue` is summed across all products before pagination so the grand total is page-independent. This mirrors how Inventory Summary builds a `LengthAwarePaginator` from a fully-computed collection.

### Decision: Transaction-type label mapping

Map internal transaction `type` codes to Indonesian labels for the "Tipe transaksi" column: `BUY` → Pembelian, `SELL`/`DISPATCH` → Penjualan, `ADJ` → Penyesuaian, `TRF` → Transfer (with masuk/keluar by delta sign where useful), opening row → "Saldo Awal". Keep the mapping in one place in the service so export and view agree.

### Decision: Filters and Livewire component cloned from Inventory Summary

`InventoryValuationReport` Livewire component reuses Inventory Summary's filter scaffolding (category multi-select with search + all/any mode, product multi-select with search, period presets, export wiring), with two changes: replace the single `asOfDate` with `tanggalAwal`/`tanggalAkhir`, and drop `stockStatus`. The `FilterData` value object (`InventoryValuationReportFilterData`) carries `tanggalAwal`, `tanggalAkhir`, `categoryIds`, `categoryMatchMode`, `productIds`, plus `fromRequest`/`fromArray` constructors like the existing one.

### Decision: Export shape mirrors the sample CSV

`InventoryValuationReportExport` emits columns in the reference order: Kode Barang, Barang, Tanggal, Tipe Transaksi, No. Transaksi, Deskripsi, Mutasi, Stok di Tangan, Unit, Harga Rata-Rata, Harga Beli/Jual, Nilai — followed by per-product subtotal rows and a final grand-total row. It pulls the full (unpaginated) result collection from the service and honors active filters, consistent with the two most recent report exports.

## Risks / Trade-offs

- **[Extraction regresses Inventory Summary]** → Run the existing `InventorySummaryReportQueryServiceTest` after extracting shared helpers; the extracted methods are pure, so behavior should be identical. Land extraction as its own reviewable step.
- **[Sample only exercises "Saldo Awal" rows]** The captured sample was run for a single day, so every row is an opening balance and most values are 0 — it never exercises mid-range Pembelian/Penjualan ledger rows or the running-WAC recurrence in the ledger view. → Build test fixtures that span multiple days with purchases and sales so running stock/WAC/value transitions are explicitly asserted.
- **[Performance on large catalogs]** Replaying all transactions for every product over a wide range can be heavy (the sample setting has ~4,900 products). → Reuse Inventory Summary's bulk loading (single transaction query filtered by product IDs, grouped in memory; batched price/date maps). Group-paginate so only product sort + replay run; consider a product-id prefilter from category/product filters before loading transactions, as the summary service already does.
- **[Company-wide vs "gudang" label]** The UI header says "Stok di gudang" but valuation is company-wide. → Keep the Indonesian column label "Stok di gudang"/"Stok di Tangan" to match the reference, while documenting that the figure is the company-wide running balance (no per-warehouse split), per the agreed scope.
- **[Reference vs transfer date semantics in range filtering]** Range inclusion depends on the same date resolution the summary service uses (purchase/sale/transfer dates, falling back to `created_at`). → Reuse `resolveTransactionDate` unchanged so range boundaries and the opening-balance cutoff behave identically to the snapshot report.

## Open Questions

- Should transfers be labeled "Transfer Masuk"/"Transfer Keluar" by delta sign, or a single "Transfer" label as in some Jurnal exports? Default to sign-aware labels; adjust if the client prefers a single label.
- Default date range when none is supplied: current month (assumed) vs. current year. Defaulting to current month; confirm with the client if their Jurnal default differs.
