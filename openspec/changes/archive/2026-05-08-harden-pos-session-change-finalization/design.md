## Context

The POS session flow already records cash movement as ledger events. Checkout finalization records `CASH_SALE_IN` for cash sale impact and records `CHANGE_OUT` when cash change is returned to the customer. `PosSessionExpectedCashCalculator` then calculates expected cash by summing event directions, so backend expected cash already understands change as an OUT movement.

The weak point is the supervisor finalization UI. The modal currently derives its own expected cash from selected event types, ignoring `CHANGE_OUT`, while the backend summary already returns `expected_cash_total`. This creates a mismatch for ordinary cash overpayment cases such as Rp990,000 paid with Rp1,000,000 and Rp10,000 returned.

## Goals / Non-Goals

**Goals:**

- Make backend `expected_cash_total` the only source of truth for finalization variance.
- Add explicit summary totals for `cash_tendered_total`, `change_total`, and `net_cash_sales_total`.
- Show `CHANGE_OUT` as a visible and filterable customer-change cash event.
- Preserve existing checkout posting and cash ledger records.
- Cover the exact overpayment/change scenario with focused tests.

**Non-Goals:**

- No historical cash event migration or backfill.
- No change to checkout payment capture rules.
- No change to receipt display unless existing receipt behavior is directly broken by the summary contract.
- No rewrite of the POS session summary page layout beyond the affected cash rows and filter.

## Decisions

### Backend summary totals are explicit

`PosSessionSummaryService` will compute terminal-session cash totals from persisted checkout payment rows and checkout change totals:

- `cash_tendered_total`: sum of cash payment entries before returned change.
- `change_total`: sum of posted checkout `change_total`.
- `net_cash_sales_total`: `cash_tendered_total - change_total`.

Alternative considered: keep deriving these totals in JavaScript from `cash_events`. That keeps the endpoint smaller, but it repeats business logic in the browser and caused the current mismatch. Backend totals are easier to test and safer for future UI changes.

### Finalization variance uses backend expected cash

The finalization modal will set its expected cash from `session.expected_cash_total` returned by the summary endpoint. Component rows will explain the amount, but they will not define the settlement truth.

Alternative considered: update JavaScript expected-cash derivation to include `CHANGE_OUT`. This fixes the immediate bug but leaves two sources of truth. Backend-only settlement truth is more reliable.

### Cash breakdown separates sales, tendered cash, and change

The finalization modal will show net cash sales as the primary `Penjualan Kas` amount, while also showing `Tunai Diterima` and `Kembalian` so supervisors can reconcile physical cash movement. For Rp990,000 paid with Rp1,000,000 and Rp10,000 change, the modal must communicate:

- Penjualan Kas: Rp990,000
- Tunai Diterima: Rp1,000,000
- Kembalian: Rp10,000
- Kas Ekspektasi: backend expected cash

Alternative considered: show only net cash sales. That avoids clutter, but it hides why a customer tendered amount differs from sales value.

### Timeline filter includes customer change

The session detail timeline already includes cash event rows. The filter set will add `CHANGE_OUT` labelled as `Kembalian`, so supervisors can isolate change movements during review.

## Risks / Trade-offs

- Backend summary totals may disagree with malformed historical events if checkout payment rows and cash events were previously inconsistent. Mitigation: preserve historical data behavior and calculate new display totals from posted checkout/payment records only.
- The term `Penjualan Kas` can be interpreted as tendered cash or net sales. Mitigation: define it as net cash sales in the spec and display `Tunai Diterima` separately.
- JavaScript formatting/parsing can still create display-only inconsistencies. Mitigation: use numeric values from JSON for variance and avoid parsing formatted currency text for core variance calculation.

## Migration Plan

No database migration is planned. Deploy as an application change:

1. Extend summary JSON fields.
2. Update the finalization modal to consume those fields.
3. Update the timeline filter.
4. Run focused POS feature tests.

Rollback is application-only: revert the service, view, JavaScript, and test changes. Existing data remains valid.

## Open Questions

None. The product decisions are settled for this proposal: net cash sales is the primary `Penjualan Kas`, backend expected cash is the variance source of truth, and historical data is not backfilled.
