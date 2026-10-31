# Design

## Context

See proposal.md for the problem and scope. Operational `/purchases` and `/sales` pages mount shared Livewire PurchaseTable and SaleTable components; Global Payment and supplier/customer workspaces reuse them with different access and eligibility constraints. Their row templates select stored `due_amount` in normal mode and live due in global mode. Normal outstanding/overdue predicates also use stored due. Purchase outstanding sorting currently orders the stored field. Sales has no outstanding-column sort control to add.

Normal/global controllers reuse each module's `show.blade.php`; the totals summaries have no outstanding row. Purchase live due is total minus active payments, clamped at zero. Sales additionally subtracts credits associated with active payments and rounds at stored currency precision. Purchase table already preloads an active-payment sum. Sales live accessors currently query settlement per model, including a separate credit sum, so directly replacing row fields would introduce or broaden N+1 queries.

Related specs preserve active-payment settlement semantics (`payment-record-maintenance`, `sale-payment-invalidation`) and define separate summary-card metrics (`sales-summary-cards`). This change adds a document-balance presentation contract without redefining payment writes or those metrics.

## Goals / Non-Goals

**Goals:** One meaning for live outstanding across affected pages, equivalent SQL filtering/sorting before pagination, and bounded settlement-query loading.

**Non-Goals:** Reconciliation/backfill, changing historical payment facts, adding payment controls or new sales sorting UI, reports/exports/legacy Yajra lists, summary-card total calculations, redefining explicit stored payment-status filters, or POS receipt changes. Existing eligibility and status constraints continue to compose with live balance predicates. Counts or totals in pre-existing cards can still differ on stale data; correcting those separate metrics needs its own scope.

## Decisions

### Reuse canonical settlement semantics with a query projection

Expose reusable model-level query projections of effective settlement/live due for list queries, following existing scopes and `active_payments_sum` conventions. Purchase already preloads active sums. For sales, include both active monetary payments and active-linked credit applications using separate aggregates so joining credits cannot duplicate monetary rows. Add an optional preloaded credit-sum path to `getEffectivePaidAmount()` only if needed, keeping its existing standalone query fallback and numeric behavior. A model-level projection or narrow reusable query helper is preferable to duplicating SQL in Blade and both Livewire components.

Projected due, accessor due and filter/order expressions must agree on active states, zero floor and currency precision. Purchase payment aggregate units must match the existing effective-payment accessor and storage conventions; do not divide or multiply values merely because another module stores money differently. Reuse or extend the existing purchase live-due scope implementation and sales canonical formula rather than creating competing formulas. Verify SQLite test and MySQL-compatible SQL.

Reject header reconciliation on reads: it would mutate financial records just to render a page. Reject invoking payment accessors without preloading on every row: sales would query both payments and credits repeatedly.

### Use live balances in the shared UI and existing balance controls

Remove mode-based row balance selection. Add one labeled outstanding row after Total Keseluruhan in each shared detail template, keeping its existing formatter (`formatRupiah` for purchase detail, `format_currency` for sales). Controllers may preload needed aggregates, especially where payment history relations are already loaded, but authorization stays in its current route/controller.

Apply existing live-positive predicates in both normal/global modes for outstanding and overdue filtering. For existing `due_amount` sorting, order by the clamped projected balance rather than the stored column, retaining the existing identifier tie-break. Do this in SQL before pagination; do not sort/filter only the current page in PHP. Keep other sort fields, explicit payment-status filters, dates, workflow status, archive state, supplier/customer and business constraints intact. Do not change recent-collection semantics or summary-card computations.

### Verify the semantic change with deliberately stale fixtures

Add model/query tests comparing projected balances, accessors, predicates and order for no payment, partial payment, zero/over-settlement, invalidated payments and active-linked sales credit. Store header balances deliberately wrong in list/detail tests so an implementation that still reads headers fails.

Extend `GlobalPurchasePaymentTableTest` and `GlobalSalePaymentTableTest` or dedicated focused Livewire classes for both modes, including embedded context isolation. Use at least two pages with stored/live orders reversed for purchase due sorting and tie cases. Test the actual normal and global detail routes with existing permissions. Compare database values before/after GET/render to prove no reconciliation. Count settlement-related queries for small versus larger pages, excluding unrelated lazy-loaded UI relations, to catch per-row payment/credit queries.

Run targeted `php artisan test --filter=...` cases with the existing isolated SQLite setup, plus affected global filter/order/isolation regressions. Reuse existing payment invalidation/credit tests to protect write behavior; no full-suite requirement for this change unless focused failures reveal broader effects. Record exact commands/results and any unavailable manual check in `verification.md` during apply.

## Risks / Trade-offs

- Query projection can diverge from accessor semantics: assert parity with active credit, invalidated evidence, fractional amounts and over-settlement cases.
- Sorting raw negative balances can disagree with displayed zero: clamp before ordering and test zero ties.
- Normal list conversion can make legacy stale headers visibly different: this is intentional; no records are silently repaired.
- Sales credit aggregation can double-count joined monetary payments: aggregate each settlement source independently.
- Broad table reuse could change document access: retain route gates and normal/global query constraints; run cross-business and embedded-context tests.
- Historical summary-card contracts still use stored metrics: preserve them explicitly, while live outstanding/overdue predicates determine table membership under the existing remaining constraints.

## Migration Plan

Deploy query/model, Livewire and view updates together after focused checks. No schema migration, data backfill or new permission is required. Revert code to restore previous presentation without altering settlement evidence.
