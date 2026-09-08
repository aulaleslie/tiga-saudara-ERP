## Why

POS transaction 3393 saves a rounded grand total of Rp318,000 while its receipt and detail rows sum to Rp317,992 because the rounded row amounts are lost or recomputed from unit prices. Rounded amounts must remain the amounts billed, settled, posted, reported, and used for returns.

## What Changes

- Persist calculated row amounts and header totals from the same POS snapshot for draft saves and checkout completion.
- Display authoritative saved row amounts consistently in transaction details and receipts, preserving monetary decimals where present. Retain existing POS two-decimal support.
- Preserve existing automatic row-total rounding, decimal unit prices, manual override authority, and deterministic downstream allocations.
- Provide an explicit, previewable repair operation for affected drafts, including 3393; do not silently reprice on read or rewrite completed transactions.
- Verify discounts, tax, owner splits, payment allocation, reporting, and returns reconcile to the captured amount.

## Capabilities

### New Capabilities

- `pos-rounded-amount-consistency`: A durable POS row-amount contract across persistence, presentation, downstream consumers, and explicit repair of affected drafts.

### Modified Capabilities

None. Existing rounding eligibility and historical-document stability requirements remain in force; this capability adds POS persistence, presentation, and repair guarantees.

## Impact

Primarily POS transaction saving, snapshot mapping, receipt mapping, transaction/receipt views, and a scoped draft repair command or service. Checkout planning, generated sales, payment allocation, reporting, and POS returns require regression verification and corrections only where they violate the shared amount contract. No new external dependencies, changes to Purchase rounding, or automatic historical-data migration are planned.

Verification is limited to focused automated tests for affected behavior and OpenSpec validation; no full-suite test run is planned or required. Browser testing and visual receipt checks are performed only by a human, with a concise handoff checklist.
