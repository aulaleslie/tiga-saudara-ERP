## Why

Global payment workspaces currently exclude unpaid documents after a partial return, even though they remain open receivables or payables. This blocks settlement of valid outstanding balances and makes the global payment lifecycle inconsistent with operational dispatch, receiving, and partial-return workflows.

## What Changes

- Expand global sales-payment eligibility to include non-archived, positive-live-balance sales in `RETURNED PARTIALLY`, in addition to dispatched sales.
- Expand global purchase-payment eligibility to include non-archived, positive-live-balance purchases in `RECEIVED PARTIALLY` and `RETURNED PARTIALLY`, in addition to `RECEIVED` purchases.
- Apply each domain's status policy consistently to global lists, summaries, read-only detail/history routes, allocation candidates, starting-document checks, and locked submission validation.
- Explicitly exclude fully `RETURNED` sales and purchases from global payment discovery and settlement, regardless of any historical stored balance.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `global-sales-multi-payment`: Permit partially returned dispatched sales to appear and be settled globally; retain full-return exclusion.
- `global-purchase-multi-payment`: Permit partially received and partially returned purchases to appear and be settled globally; retain full-return exclusion.

## Impact

- Affects global-payment eligibility queries and submission guards in `Modules/Sale`, `Modules/Purchase`, and their shared Livewire list and summary components.
- Affects global payment controller/service feature coverage for lifecycle statuses.
- Does not add migrations, modify payment balances or ledgers, or change normal setting-scoped payment workflows.
