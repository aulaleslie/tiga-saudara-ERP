## Why

POS session finalization can give supervisors the wrong impression when a cash transaction includes change. A checkout such as Rp990,000 paid with Rp1,000,000 and Rp10,000 returned must distinguish net cash sales, cash tendered, and change returned so the finalization modal and backend expected cash use the same settlement truth.

## What Changes

- Extend the POS session summary contract with explicit backend totals for:
  - cash tendered by customers before change
  - change returned to customers
  - net cash sales after change
- Make the supervisor finalization modal use backend `expected_cash_total` as the source of truth for variance calculation.
- Display change returned as a first-class row in the finalization cash breakdown.
- Display both net cash sales and cash tendered/change context for sessions with cash overpayment.
- Add a `CHANGE_OUT` / `Kembalian` filter to the POS session cash events timeline.
- Preserve historical data behavior; no migration or backfill is required.
- Add focused test coverage for the Rp990,000 sale, Rp1,000,000 cash tendered, Rp10,000 change scenario.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `pos-session-summary`: Add explicit change-aware cash totals to terminal session summary data.
- `pos-supervisor-cash-finalization`: Clarify finalization cash breakdown and require backend expected cash as the variance source of truth.
- `pos-session-detail-page`: Require `CHANGE_OUT` cash events to be filterable in the timeline as customer change.

## Impact

- Affects POS session summary data from `Modules/Pos/Services/PosSessionSummaryService.php`.
- Affects supervisor finalization modal display and variance calculation in `Modules/Pos/Resources/views/session/_finalize-modal.blade.php` and `public/js/pos-session-handlers.js`.
- Affects session summary/detail page cash event filters in `Modules/Pos/Resources/views/session/summary.blade.php` and `public/js/pos-session-detail-handlers.js`.
- Requires focused POS feature tests around summary JSON, finalization modal data expectations, and change-aware cash event filtering.
