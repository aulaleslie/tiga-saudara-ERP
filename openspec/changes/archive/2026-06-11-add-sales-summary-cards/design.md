## Context

The purchases list renders `<livewire:purchase.purchase-summary-cards />` (`Modules/Purchase/Livewire/PurchaseSummaryCards.php`) showing AP cards. The sales list (`Modules/Sale/Resources/views/index.blade.php`) renders only `<livewire:sale.sale-table />` with no cards. We mirror the purchase component into the Sale module with AR semantics. The Sale entity exposes `due_amount`, `paid_amount`, `due_date`, `payment_status`, `STATUS_DISPATCHED*`, and `salePayments()`; `SalePayment` exposes an `active()` scope.

## Goals / Non-Goals

**Goals:**
- One Livewire component, `Modules\Sale\Livewire\SaleSummaryCards`, with three computed properties mirroring `PurchaseSummaryCards`.
- Setting-scoped AR metrics that match the visual layout of the purchase cards.

**Non-Goals:**
- No global (cross-setting) variant — purchase cards are setting-scoped only and we match that.
- No new permission; the sales list gate governs visibility.
- No changes to the sales report pages (covered by the other two changes).

## Decisions

- **Mirror the structure, flip the semantics.** Reuse the purchase component's three-card shape but recast as receivables: open receivables, overdue receivables, recent collections. Alternative — inventing new sales-specific KPIs — was rejected to keep the two list pages visually and conceptually consistent.
- **Status set is the dispatched family.** Use `STATUS_APPROVED`, `STATUS_DISPATCHED_PARTIALLY`, `STATUS_DISPATCHED` where purchases use the received family. These are the sales states in which a receivable is real.
- **Do NOT divide collections by 100.** `PurchasePayment` mutates `amount` to cents (×100) and the purchase card divides by 100; `SalePayment.amount` is cast `decimal:2` (rupiah). The collections property sums `SalePayment.amount` directly. This is the single highest-risk divergence and is asserted by a dedicated test.
- **Keep the active-payments-with-fallback approach.** Primary source is `SalePayment::active()` joined to in-setting PAID sales in the last 30 days (distinct invoice count + summed amount); fallback to PAID sales' `paid_amount` when no active payment rows exist, mirroring the purchase fallback.

## Risks / Trade-offs

- [Copying the `/100` from the purchase card] → Mitigated by an explicit assertion that a known `SalePayment.amount` surfaces unscaled in the collections total.
- [Wrong status family yields empty/incorrect cards] → Mitigated by tests seeding dispatched vs draft/rejected sales and asserting inclusion/exclusion.
- [Performance on large sales tables] → Each card is a single aggregate query (`COUNT`/`SUM`); matches the purchase card cost profile.
