## Why

The sales transaction list (`sales.index`) drops straight into a table with no at-a-glance financial summary, while the purchases list shows three summary cards (Belum Dibayar / Telat Bayar / Pelunasan) so users instantly see what they owe. Sales managers need the mirror image for accounts receivable — what customers owe, what is overdue, and what has recently been collected — without running a report.

## What Changes

- Add a `SaleSummaryCards` Livewire component to the Sale module, rendered at the top of `sales.index`, mirroring `PurchaseSummaryCards` but with accounts-receivable (AR) semantics:
  - **Piutang Belum Tertagih** — count and total of open receivables (`due_amount > 0`, payment status UNPAID/PARTIAL, dispatched-family document statuses).
  - **Piutang Telat** — the subset of the above whose `due_date` is past today.
  - **Penerimaan (30 hari)** — count of unique invoices and total collected via active `SalePayment` rows in the last 30 days, with a fallback to `paid_amount` on fully-paid sales.
- Scope all metrics to the current `setting_id` (non-global), matching the purchase cards' behavior.
- **NON-OBVIOUS**: `SalePayment.amount` is stored in rupiah (cast `decimal:2`), unlike `PurchasePayment.amount` which is stored in cents (×100). The sales collections card MUST NOT divide by 100.

## Capabilities

### New Capabilities
- `sales-summary-cards`: AR summary cards on the sales list showing open receivables, overdue receivables, and recent collections, scoped to the current setting.

### Modified Capabilities
<!-- None: no existing spec governs the sales list page summary. -->

## Impact

- New: `Modules/Sale/Livewire/SaleSummaryCards.php`, `Modules/Sale/Resources/views/livewire/sale-summary-cards.blade.php`.
- Modified: `Modules/Sale/Resources/views/index.blade.php` (insert the component above `sale.sale-table`).
- Reads: `Modules\Sale\Entities\Sale`, `Modules\Sale\Entities\SalePayment` (uses `STATUS_DISPATCHED*` statuses and the `active()` payment scope).
- Permission: reuses existing sales list access gate (`sales.access`); no new permission.
- Tests: new `Modules/Sale/Tests/Feature/SaleSummaryCardsTest.php` mirroring `PurchaseSummaryCardsTest`, with explicit coverage that collections are NOT divided by 100.
