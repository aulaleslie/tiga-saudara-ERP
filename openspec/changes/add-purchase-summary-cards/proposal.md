## Why

The purchase list page currently shows no at-a-glance financial health indicators, requiring users to manually scan rows to understand outstanding obligations. Adding summary cards at the top of the list gives purchasers and finance staff immediate visibility into unpaid invoices, overdue invoices, and recent settlements.

## What Changes

- New Livewire component `PurchaseSummaryCards` rendered above the existing DataTable on the purchase index page
- Three summary cards displaying live counts and totals:
  - **Faktur belum dibayar** — UNPAID invoices with outstanding balance (`due_amount > 0`), status in `[APPROVED, RECEIVED PARTIALLY, RECEIVED]`
  - **Faktur telat bayar** — subset of above where `due_date < today`
  - **Pelunasan 30 hari terakhir** — PAID invoices in last 30 days (date from `purchase_payments.date` if available, fallback to `purchases.date`)
- Clicking a card pre-filters the DataTable below to the corresponding subset

## Capabilities

### New Capabilities

- `purchase-summary-cards`: Summary card widget on purchase list showing unpaid, overdue, and recently settled invoice counts and totals with DataTable filter integration

### Modified Capabilities

_(none — no existing spec-level behavior changes)_

## Impact

- **New file**: `Modules/Purchase/Livewire/PurchaseSummaryCards.php`
- **New view**: `Modules/Purchase/Resources/views/livewire/purchase-summary-cards.blade.php`
- **Modified**: `Modules/Purchase/Resources/views/index.blade.php` — add `<livewire:purchase.purchase-summary-cards />` above the DataTable
- **Queries**: Read-only aggregates on `purchases` and `purchase_payments` tables
- **No migrations, no API changes, no breaking changes**
