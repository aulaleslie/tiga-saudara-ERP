## Why

POS cash reconciliation currently ignores change given to customers during transactions. When a customer pays with cash and receives change, the expected cash total doesn't account for the money returned to the customer. This causes the "Kas" column on the session index to show an inflated amount, misrepresenting what should physically be in the drawer. For example: opening float 2M + cash sales 5M - change given 1M should equal 6M, but the system shows 7M.

## What Changes

- Add a new event type `EVENT_CHANGE_OUT` to track change given to customers as cash outflow
- When a checkout is finalized with change, create a CHANGE_OUT event in addition to the CASH_SALE_IN event
- The `expected_cash_total` will now correctly reflect physical cash expected in the drawer: opening + cash in - change out - safe drops
- The "Kas" column on the session index will now show accurate expected cash for counting

## Capabilities

### New Capabilities
- `pos-change-outflow-tracking`: Track change given to customers as cash outflow events that reduce expected cash total

### Modified Capabilities
- `pos-session-expected-cash-calculation`: Expected cash calculation now includes change outflow events in the reconciliation formula
