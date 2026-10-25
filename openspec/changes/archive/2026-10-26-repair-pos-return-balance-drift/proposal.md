# Proposal

## Why

Historical POS return recalculation can reconstruct sale headers from rounded unit prices, losing authoritative row amounts. For transaction 9218, a Rp0.40 header shortage is redistributed across receipt rows, producing unexpected decimals although the stored remaining rows already total Rp30,105,000.

## What Changes

- Add `pos:repair-return-balances` for explicitly selected transaction IDs and business scope, defaulting to dry-run.
- Reconcile original captured charges, completed cash returns, current sale rows, payments, and receipt projections; repair only proven historical header/balance drift.
- Preserve authoritative remaining rows, completed returns, payment records, quantities, tax evidence, stock, serials, and immutable checkout snapshots. Ambiguous or inconsistent row evidence is reported and skipped rather than rounded blindly.
- Apply atomically with locks, fresh evidence validation, actor attribution, before/after audit evidence, and idempotent reruns.
- Permit this explicit corrective operation as a narrow exception to existing completed-transaction immutability and the transaction-9218 repair exclusion. Deployment and ordinary reads remain read-only.
- Verify through focused command/service and receipt-projection tests using synthetic historical-return fixtures; no full-suite run is planned.

## Capabilities

### New Capabilities

- `pos-return-balance-repair`: Evidence-based historical return balance repair and receipt reconciliation.

### Modified Capabilities

- `pos-return-approval-execution`: Allow the dedicated balance repair without retroactively changing completed return allocations; preserve the ban on mutating verification against transaction 9218.
- `pos-rounded-amount-consistency`: Narrowly permit explicit completed-sale header/balance correction while preserving recorded commercial rows and snapshots.

## Impact

`Modules/Pos/Console`, a repair service under `Modules/Pos/Services`, module command registration, existing audit infrastructure (or an additive repair-audit table if necessary), and focused tests. Existing receipt and settlement projection services provide acceptance checks. No automatic historical repricing, full-database scan, refund replay, payment reclassification, or change to future return policy.
