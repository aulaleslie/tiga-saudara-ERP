## Why

The POS transactions page can remain stuck on `Memuat data...` because client scripts are not reliably injected, making saved drafts appear missing even when data exists. At the same time, the current POS reports UI is table-heavy and visually weak, reducing confidence for operational review and management reporting.

## What Changes

- Fix POS transactions list bootstrapping so client-side loading logic consistently executes and no longer gets stuck in the initial loading placeholder.
- Standardize POS page script injection patterns for transaction-related and reporting screens to prevent silent script drop/mismatch across layouts.
- Improve POS transaction list UX states (loading, empty, error, and refresh behavior) so users can clearly distinguish “no data” from “failed to load.”
- Redesign POS reports into a professional dashboard-style experience with clear hierarchy:
  - KPI summary strip
  - tabbed detail sections
  - stronger visual structure and readability for finance/operations use
- Preserve existing reporting endpoints and business filters while modernizing presentation and interaction.

## Capabilities

### New Capabilities
- `pos-transactions-list-loading`: Ensure POS transaction list pages reliably execute client scripts and provide deterministic loading/error/empty states.
- `pos-reports-professional-dashboard`: Provide a professional POS report dashboard with KPI-first layout and detail tabs for daily sales, cashiers, payments, items, and approvals.

### Modified Capabilities
- _(none)_

## Impact

- **Views/Layout**: `resources/views/includes/main-js.blade.php`, POS transaction/report/monitor/reconciliation views.
- **Frontend behavior**: POS list/report script boot sequence, loading indicators, and tab interactions.
- **Reporting presentation**: report information architecture, typography/spacing, and visual grouping.
- **APIs/Backend**: no required endpoint contract changes expected; existing report/transaction data endpoints are reused.
