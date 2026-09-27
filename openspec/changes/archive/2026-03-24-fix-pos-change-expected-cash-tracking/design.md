## Context

The POS system tracks expected cash in a session through `PosSessionCashEvent` records. Currently, only two primary event types are created:
- `EVENT_OPEN_FLOAT`: Initial cash in the drawer
- `EVENT_CASH_SALE_IN`: Cash received from customer transactions
- `EVENT_SAFE_DROP_OUT`: Money removed from drawer to safe

When a customer pays with cash and receives change, the cash inflow is recorded but the change outflow is not. This leaves `expected_cash_total` higher than what should physically be in the drawer.

The `PosSessionExpectedCashCalculator` already supports bidirectional cash flow via `DIRECTION_IN` and `DIRECTION_OUT`, and the session index view already displays `expected_cash_total`. The infrastructure exists—we just need to emit the missing outflow event.

## Goals / Non-Goals

**Goals:**
- Track change given to customers as a CHANGE_OUT event with `DIRECTION_OUT`
- Update `expected_cash_total` to correctly reflect physical cash: opening + cash_in - change_out - safe_drops
- Ensure the "Kas" column on session index accurately represents expected drawer contents for counting
- Maintain backward compatibility with existing cash event queries and reporting

**Non-Goals:**
- Modify how change is calculated or stored in `pos_checkouts.change_total` (that remains unchanged)
- Change the session index UI or column layout
- Implement change tracking for legacy single-payment checkouts differently than multi-payment
- Add new permissions or validation rules for change tracking

## Decisions

### Decision 1: Event Type
**Choice:** Create new `EVENT_CHANGE_OUT` constant in `PosSessionCashEvent`

**Rationale:** Follows existing pattern for event types (`EVENT_SAFE_DROP_OUT`). Provides semantic clarity in queries—you can filter by event type to see only change-related events. Alternative would be to use `EVENT_SAFE_DROP_OUT` for both safe drops and change, but that conflates different business operations.

**Alternatives Considered:**
- Use existing `EVENT_SAFE_DROP_OUT` for change: Less clear intent, harder to distinguish change from actual safe drops in reports
- Add a `reason` field to track change within CASH_SALE_IN: Event direction logic is already built on direction field; this approach is cleaner

### Decision 2: Event Creation Timing
**Choice:** Create CHANGE_OUT event immediately after CASH_SALE_IN event in `FinalizePosCheckoutService.postCheckout()`, synchronously in the same transaction

**Rationale:** Ensures consistency—both events are created or neither are. Leverages existing transaction boundary that already persists the checkout. Uses the same checkpoint where `actualChangeTotal` is calculated.

**Alternatives Considered:**
- Create as async job: Added complexity; not needed for correctness since change is deterministic from the checkout
- Create separately at session close: Too late; expected_cash_total would be wrong during the session

### Decision 3: Scope: Both Single-Payment and Multi-Payment
**Choice:** Apply change tracking to both single-payment and multi-payment checkouts using the same logic

**Rationale:** The `actualChangeTotal` is calculated the same way for both paths (lines 612-620 in FinalizePosCheckoutService). Treating them identically ensures no gaps. Tests already cover multi-payment change scenarios.

**Alternatives Considered:**
- Only multi-payment: Inconsistent behavior; single-payment cash transactions would still inflate expected_cash
- Different logic per type: Added complexity with no benefit; calculation is unified

## Risks / Trade-offs

**[Risk: Historical Data Gap]** Existing sessions and transactions before this change won't have CHANGE_OUT events, leaving their expected_cash_total unchanged (potentially still inflated).

→ **Mitigation:** This is acceptable because:
1. Users count actual cash at session close, not using expected_cash_total from mid-session
2. The issue only affects the "Kas" column during OPEN sessions; closed sessions use `counted_cash_total` instead
3. A data migration is not required for correctness—it only affects historical session views
4. If needed later, a migration script can backfill CHANGE_OUT events from existing pos_checkout.change_total records

**[Risk: Change Amount Precision]** Floating-point rounding in change calculation could cause small discrepancies

→ **Mitigation:** Follow existing pattern: round to 2 decimals using `round(..., 2)` at both creation and calculation points (already done)

**[Risk: Checkout Posting Failure]** If CHANGE_OUT event creation fails after CASH_SALE_IN is created, the transaction rolls back but the session state is inconsistent

→ **Mitigation:** Already handled—entire `postCheckout()` method is in a DB transaction, so both events succeed or both fail atomically
