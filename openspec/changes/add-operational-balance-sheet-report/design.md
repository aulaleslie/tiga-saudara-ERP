## Context

The ERP already has Reports module patterns for landing cards, Livewire report screens, service-backed calculations, and XLSX exports. It also has `chart_of_accounts`, `journals`, and `journal_items`, but those are not yet a complete accounting source of truth for operational modules. Existing financial reporting, such as the current profit-loss report, derives totals directly from operational documents.

The Neraca report will therefore be an operational balance sheet. It will calculate management-facing asset, liability, and equity buckets from sales, purchases, returns, payments, expenses, inventory, and settings data for the current tenant setting as of a selected date. The report must not present rows as chart-of-account balances, so it omits account numbers and includes a note that values are derived from transactions rather than accounting journals.

## Goals / Non-Goals

**Goals:**
- Add a usable Neraca report under Reports > Sekilas bisnis.
- Calculate balances from existing operational transactions scoped to the active `setting_id`.
- Support an as-of date filter and XLSX export.
- Display sections for assets, liabilities, and equity with balanced totals.
- Reuse existing Laravel, Livewire, Reports module, export, permission, currency, and formatting patterns.
- Make calculation rules explicit and covered by focused tests.

**Non-Goals:**
- Do not implement general ledger posting or double-entry accounting.
- Do not use `journal_items` as the report source in this iteration.
- Do not add chart-of-account hierarchy, account depth filtering, or account number output.
- Do not add comparison periods in the first version.
- Do not introduce opening capital configuration in this first iteration.
- Do not change existing transaction lifecycle behavior.

## Decisions

### Decision: Source the report from operational transactions

Use sales, purchases, returns, payments, expenses, and inventory valuation as the source of report buckets instead of `journal_items`.

Rationale: Operational modules contain the data users currently trust. Journal data is manually entered and not yet automatically posted from all related transactions, so it would understate or misrepresent business balances.

Alternative considered: Use chart-of-account and journal balances. Rejected for this iteration because the journal subsystem is not yet the operational source of truth.

### Decision: Present operational buckets without account numbers

The table will use columns for row name and amount. Account numbers are omitted.

Rationale: Rows like "Kas / Bank dari transaksi" and "Modal / Ekuitas" are derived buckets, not chart-of-account rows. Showing account numbers would imply ledger accuracy the system does not yet provide.

Alternative considered: Map buckets to synthetic account numbers. Rejected because it would add false precision and unnecessary future migration friction.

### Decision: Use an as-of date, not a date range

The report accepts one as-of date and includes eligible operational activity up to and including that date.

Rationale: A balance sheet is a point-in-time report. Date ranges belong to profit-loss, cash flow, and movement reports.

Alternative considered: Include `start_date` and `end_date`. Rejected for first version because it complicates equity and retained earnings without improving the core point-in-time balance view.

### Decision: Use existing completed/active document semantics

Sales count when status is `DISPATCHED`, `RETURNED PARTIALLY`, or `RETURNED`. Purchases count when status is `RECEIVED`, `RETURNED PARTIALLY`, or `RETURNED`. Returns count only when completed. Expenses count only when approved and not archived. Payments count only active/non-invalidated payment rows where the underlying payment model supports status.

Rationale: These rules match existing report behavior and avoid counting drafts, rejected documents, partially received purchases, or incomplete dispatches as balance sheet events.

Alternative considered: Count all approved-but-not-final documents. Rejected because partial operational states would overstate assets and liabilities.

### Decision: Derive cash/bank from payment activity

Cash/bank is calculated from operational payment flows through the as-of date:
- inbound sale payments increase cash/bank
- purchase payments decrease cash/bank
- approved expenses decrease cash/bank
- sale return refunds decrease cash/bank
- purchase return refunds or supplier credits settled as cash increase cash/bank where represented by payment records

Rationale: There is no bank ledger or opening cash source of truth yet. Payment-derived cash is the most concrete operational approximation available.

Alternative considered: Use payment method chart-of-account mappings. Rejected because that still depends on incomplete accounting balances and does not solve opening cash.

### Decision: Derive receivables, payables, and equity from operational balances

Receivables are outstanding customer balances from eligible sales adjusted for sale return effects where existing data supports it. Payables are outstanding supplier balances from eligible purchases adjusted for purchase returns where existing data supports it. Equity is derived as `total_assets - total_liabilities`.

Rationale: Without opening capital or a ledger, equity cannot be independently known. A derived equity row keeps the balance sheet balanced while clearly reflecting first-version constraints.

Alternative considered: Split equity into owner capital, retained earnings, and current earnings. Deferred until an opening balance/capital source is introduced or the operational profit formula is hardened.

### Decision: Reuse inventory valuation behavior

Inventory value should reuse the existing inventory valuation report/service behavior where possible. If the current service only supports current valuation, the first implementation should encapsulate the calculation behind the Neraca service so it can be hardened later for historical as-of valuation.

Rationale: Inventory valuation is high-risk and already has reporting behavior elsewhere. Duplicating formulas increases drift.

Alternative considered: Calculate `product_quantity * product_cost` directly in the Neraca service. Acceptable only as a fallback if the existing valuation service cannot be reused cleanly.

## Risks / Trade-offs

- Payment-derived cash may not equal real cash/bank balance because opening cash, bank transfers, owner capital injections, and manual cash movements are not fully represented. → Label the row clearly and add a report note.
- Derived equity hides the distinction between owner capital and retained earnings. → Use a single "Modal / Ekuitas" row in the first version and defer detailed equity breakdown.
- Inventory as-of valuation may be limited by available valuation services and transaction history. → Encapsulate inventory calculation and test the selected formula.
- Return settlement data can be nuanced across cash refunds, credits, and replacement workflows. → Start with completed returns and existing payment/settlement records, then harden in a later iteration.
- Report totals can appear precise while still being operational approximations. → Include an explicit non-ledger note in the UI and export.

## Migration Plan

No database migration is required for the first version. Deployment is additive: add routes, controller/view, Livewire component, service/value objects, export class, landing card route, and tests. Rollback is removing the new route/card/component/service/export files and restoring the Neraca card to placeholder status.

## Open Questions

- Should payment-derived cash include POS session opening float and cash movements in the first implementation, or remain limited to document payments?
- Should inventory valuation use current product quantities or reconstruct quantities as of the selected date from stock transactions when the selected date is in the past?
- Should completed sale and purchase returns appear as separate asset/liability rows, or only adjust receivables/payables/cash where settlement data is available?
