## Why

The `/pos/sessions/{id}/summary` endpoint currently returns JSON but has no UI to display it. Users clicking "Detail" on a session see raw JSON instead of a proper detail page. This blocks visibility into session activity, cash events, and transactions—critical for cashiers and managers to reconcile cash and audit operations.

## What Changes

- Convert the summary endpoint from JSON-only to serve a full Blade view
- Create `session/summary.blade.php` view with four integrated sections:
  - **Session Overview**: Key metrics (opening float, expected cash, threshold, variance)
  - **Cash Events Timeline**: Filterable chronological list of all cash events with performer/approver info
  - **Transactions Ledger**: Last 50 transactions; rows link to checkout detail modals
  - **Action Buttons**: Conditional close/finalize buttons using existing handlers
- Keep `PosSessionSummaryService` unchanged; reuse its data structure
- Maintain existing authorization checks (owner can view own, `pos.sessions.view` permission for all)

## Capabilities

### New Capabilities
- `pos-session-detail-page`: Full-page view for session summary with cash events timeline, transaction ledger, and filterable event display
- `pos-checkout-modal-detail`: Modal popup displaying complete checkout details when drilling down from transaction ledger

### Modified Capabilities
- `pos-session-summary-endpoint`: Endpoint behavior changes from JSON-only to rendering a Blade view (authorization and data structure unchanged)

## Impact

**Code changes:**
- `Modules/Pos/Http/Controllers/PosSessionController.php`: Modify `summary()` method to render view instead of returning JSON
- New: `Modules/Pos/Resources/views/session/summary.blade.php`
- New: `public/js/pos-session-detail-handlers.js` for modal interactions

**Frontend:**
- Detail links now show a full page instead of raw JSON
- Transaction rows open a modal with full checkout details (click interaction)
- Cash events filterable by type (button toggles)

**APIs:**
- Endpoint behavior changes from `return response()->json()` to `return view()`
- Endpoint still accepts same URL pattern and parameters
- Authorization logic unchanged

**No breaking changes** to external APIs; this is internal view rendering.
