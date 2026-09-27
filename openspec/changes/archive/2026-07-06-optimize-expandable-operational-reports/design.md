## Context

The current Buku Besar Livewire component calls `OperationalGeneralLedgerReportService::generate()` during render, and that service builds every selected bucket's movement rows before the Blade view can collapse or hide anything. The inventory detail and inventory valuation reports have the same shape: each render loads all matching products, loads all matching stock transactions, replays every product timeline, builds all ledger rows, and then paginates the already-built grouped array.

This makes the visible table expensive even when the user only needs summary totals. It also makes pagination less effective because the cost has already been paid before page slicing.

Inventory valuation has a second issue: the replay helper resolves purchase unit cost from `purchase_details.price` or `unit_price`, while purchase receiving and sales cost snapshot backfill use tax-exclusive line DPP. The report should align with the existing cost rule rather than inventing a parallel one.

## Goals / Non-Goals

**Goals:**

- Render Buku Besar as bucket summaries first, with detail rows loaded only when a bucket is expanded.
- Render inventory detail and inventory valuation as product summaries first, with per-product detail rows loaded only when a product is expanded.
- Clear expanded-row caches whenever filters, selected buckets, selected products, selected categories, date range, setting scope, or sorting change.
- Preserve full-detail exports for the active filters regardless of collapsed UI state.
- Share calculation primitives so summary rows and detail rows use the same filtering, ordering, balance, and cost semantics.
- Correct inventory valuation purchase replay to use tax-exclusive line DPP from stored purchase detail totals.

**Non-Goals:**

- Do not add a persisted report cache or new report snapshot table in this change.
- Do not introduce landed-cost allocation for document-level purchase shipping.
- Do not allocate document-level purchase discount into inventory cost unless it is already reflected in stored line subtotals.
- Do not change POS, Sales Return, Purchase Return, dispatch, or stock posting behavior.
- Do not change export file formats except where necessary to preserve full-detail rows while the UI becomes collapsible.

## Decisions

### Summary-first report contracts

Split each affected report service into summary and detail entry points.

- Buku Besar:
  - summary: selected bucket key, label, beginning balance, period debit, period credit, ending balance, and row count if practical.
  - detail: movement rows for one bucket, including running balance from that bucket's beginning balance.
- Inventory detail:
  - summary: product identity, opening stock, period stock in, period stock out, ending stock, and unit.
  - detail: opening row, in-period transaction rows, and subtotal for one product.
- Inventory valuation:
  - summary: product identity, opening stock/value, period stock in/out, ending stock, average cost, ending value, and unit.
  - detail: opening row, in-period valuation rows, and subtotal for one product.

Rationale: Blade-only collapse would hide rows after the expensive query/replay work has already happened. Service-level summary/detail separation changes the backend work performed for the initial render.

Alternative considered: Keep full report generation and only add Bootstrap collapse. This is simpler but fails the performance goal.

### Livewire expansion state

Each component should keep expansion state and loaded detail rows keyed by stable identifiers:

- Buku Besar: bucket key.
- Inventory reports: product ID.

On expand, the component loads rows for that key and current applied filters. On collapse, the component may retain loaded rows for the same applied filters. On filter application, reset, sort change, or scope change, it must clear expanded keys and loaded rows.

Rationale: This avoids repeated work while the user opens/closes the same group, without risking stale detail after filter changes.

Alternative considered: Use Alpine-only state with `wire:init` child components. That increases component fragmentation and makes export/filter cache invalidation harder to reason about.

### Exports remain full-detail

Existing exports should continue to call full-detail report paths. Collapsed UI state should not affect exports.

Rationale: Users expect exported Buku Besar and inventory files to be complete for the active filters. Export parity means matching the filter and calculation semantics, not matching the collapsed visual state.

Alternative considered: Export only expanded rows. This would make exports dependent on UI state and could silently omit data.

### Inventory purchase cost source

Inventory valuation replay should resolve purchase unit cost from stored purchase detail DPP:

```text
unit cost = max(0, sub_total - product_tax_amount) / quantity
```

The existing `PurchaseCostHelper::calculateUnitCost()` already represents this rule. Line-level discounts are not subtracted again because current purchase cart/import flows store `sub_total` after the line discount. Document-level purchase shipping and document-level purchase discount remain excluded from average cost in this change.

Rationale: This aligns report replay with purchase approval and sales cost snapshot rules. It also avoids double-counting line discounts.

Alternative considered: Use `price * quantity`. This is the current behavior but diverges from DPP when tax-included purchases, discounted lines, or imported totals exist.

### Reuse before deeper optimization

This change should first make the existing replay/query logic callable by scope:

- selected bucket instead of all Buku Besar buckets,
- selected product instead of all inventory products,
- summary-only calculations where row hydration is not needed.

Further optimization, such as SQL aggregate summaries, persisted cost-state snapshots, or `after_average_cost` stock ledger columns, should be deferred unless implementation shows the scoped replay still cannot meet acceptable response times.

Rationale: It keeps the change brownfield-friendly and avoids a schema migration before the interaction model is proven.

## Risks / Trade-offs

- Stale expanded rows after filter changes -> clear expanded keys and loaded detail arrays whenever applied filters change.
- Summary/detail mismatch -> implement summary and detail paths over shared helper methods and add tests that compare expanded details with export/full-detail calculations.
- Running balances in lazily loaded Buku Besar rows need the correct opening balance -> detail loading must compute or receive the bucket's beginning balance for the same applied filters.
- Inventory valuation still replays history for accurate average cost -> summary-first reduces initial row hydration but does not eliminate all replay cost; defer persisted cost snapshots to a future change if needed.
- Purchase cost correction can change historical inventory valuation totals -> cover with focused tests and document that the new result aligns with existing purchase cost helper semantics.
- Document-level shipping/discount may be expected by some users as landed cost -> this change explicitly excludes it and leaves a future landed-cost allocation proposal as the path for changing that behavior.

## Migration Plan

No database migration is planned.

Implementation should be deployable as application code and tests only. Rollback is a code rollback to the prior full-render report services and views. Existing exports and routes remain in place.

## Open Questions

- Should row counts be displayed in collapsed group headers, or only loaded internally to decide whether an expand control is shown?
- Should expanded detail rows be kept in memory after collapse, or discarded to reduce Livewire payload size on very large groups?
- Should a future landed-cost feature allocate purchase shipping into inventory value by line DPP, quantity, or weight/volume metadata?
