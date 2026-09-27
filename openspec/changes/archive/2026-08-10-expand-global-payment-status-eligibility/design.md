## Context

Both global payment flows intentionally gate documents by lifecycle status as well as archive state and canonical live balance. Sales centralizes its current three-status rule in `Sale::approvedUp()` but repeats it in its Livewire table and summaries. Purchases hard-code exact `RECEIVED` checks in the global controller, Livewire table and summaries, and the transaction service. This change broadens only partial-return/partial-receipt eligibility while preserving the full-return exclusion.

## Goals / Non-Goals

**Goals:**

- Use one explicit payable-status set per domain consistently across discovery, inspection, candidate selection, and locked settlement.
- Permit a partially returned document to be paid only when it remains non-archived and has a positive canonical live balance.
- Ensure fully `RETURNED` documents cannot be opened through global-payment routes or paid through a tampered allocation.

**Non-Goals:**

- Change payment ledgers, balance formulas, return settlement amounts, normal payment workflows, or historical records.
- Permit draft, waiting approval, rejected, or fully returned documents to be globally payable.

## Decisions

### Define explicit domain eligibility sets

Sales global payment eligibility will be `APPROVED`, `DISPATCHED PARTIALLY`, `DISPATCHED`, and `RETURNED PARTIALLY`. Purchases will use `RECEIVED PARTIALLY`, `RECEIVED`, and `RETURNED PARTIALLY`. `RETURNED` remains outside both sets.

This follows the settled business policy and allows remaining debt after a partial return to be collected or paid. Including full returns based solely on legacy `due_amount` was rejected because a completed reversal must not retain settlement eligibility.

### Centralize and reuse the status predicates

The implementation will expose a reusable eligibility scope or domain predicate for each model and replace all global-payment-specific status filters with it. Sales' existing `approvedUp()` scope will be renamed only if necessary to avoid misleading normal-workflow callers; otherwise a specifically named global-payable scope will be introduced. Purchase global controller, service, table, and summary filters will share the purchase predicate.

Duplicating arrays in each controller/service was rejected because it caused the current drift and can expose documents that submission subsequently rejects.

### Revalidate after locking

The global payment services will continue to obtain a row lock and reapply status, archive, supplier/customer, and live-balance eligibility inside the transaction. UI visibility and route access are convenience checks; only the locked service check is authoritative.

### Preserve positive-live-balance behavior

The change does not use `payment_status` or stored `due_amount` as an alternate eligibility condition. A newly eligible lifecycle status must still have a positive canonical live outstanding amount for payment creation; paid documents remain inspectable where existing workspace behavior allows them.

## Risks / Trade-offs

- [A shared sales status scope might be used by non-payment behavior] → Introduce or use a clearly named global-payment scope and characterize normal callers before changing a generic scope.
- [A list and store rule can drift] → Feature-test every newly eligible status through list/form and successful locked submission, plus full-return rejection.
- [Legacy return documents can have inconsistent header balances] → Retain the canonical active-payment balance check and do not infer eligibility from status alone.

## Migration Plan

1. Add eligibility predicates and update each global query/guard to use them.
2. Add focused sales and purchase feature tests for eligible partial states and excluded full returns.
3. Deploy as an application-only change; no migration or data backfill is required.

Rollback restores the prior status predicates. No generated data needs reversal because all payments remain existing ordinary payment records.

## Open Questions

None.
