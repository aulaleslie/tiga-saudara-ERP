# Design

## Context

See proposal.md for motivation. The current Blade payment panel has two save actions and Pilih Pembayaran. public/js/pos-staged-payment.js uses a debt toggle and a cart-token session payment chain. Adding a Kasbon payment currently proceeds toward final confirmation immediately, so multiple down payments need an explicit finish action. Posting already supports debt, terms, approvals, split sales, and payment composition.

The production trace identifies PosCheckoutPaymentSplitService::allocate: multiplying minor-unit payment and group weight produces 1.18155E+19 before intdiv, exceeding a signed 64-bit integer. This was reproduced independently with the logged values.

## Goals / Non-Goals

Goals: reuse canonical posting and permission services, isolate flow-specific progress, and preserve exact monetary reconciliation. Verification is limited to affected focused PHP/JavaScript cases and targeted visual checks.

Non-goals: full-suite tests, new ledgers, historical repair, changing customer eligibility, changing settlement priority, or changing existing approval policy.

## Decisions

### Explicit flow with a shared payment engine

Represent full payment and Kasbon explicitly in the module and session chain, retaining existing is_debt compatibility at the posting boundary. Main actions and in-modal switches call the same transition handler. Retain preflight diagnostics and authoritative permission/customer/term checks. Replace the toggle-based entry with dedicated controls; duplicating posting pipelines would increase divergence risk.

Kasbon offers Add Payment and explicit Finish Kasbon, including zero-down-payment completion. Adding a stage never forces completion. Validate the cumulative amount below the authoritative total; retain non-cash reference/evidence rules and existing multi-payment guidance.

### Responsive permission-aware actions

Render controls from existing role capabilities and save permissions, applying any required checkout authority/session prerequisites to Kasbon too. Use a grid with a larger Bayar Lunas area and a secondary vertical stack. Render no unauthorized placeholders; collapse absent columns/stacks. Authorized but temporarily unavailable actions retain prerequisite guards. Hide unauthorized in-modal switches as well. Avoid introducing new permissions.

### Confirmed reset across browser and session

Track dirty payment state including unsubmitted input and staged entries. Use the existing styled modal conventions with destination-specific wording and clear cancel/confirm actions. Preserve cart, customer, and transaction notes. Reset payment entries, drafts, references, uploaded evidence associations, term, lifecycle acknowledgement, and checkout approval state. Reset must not delete historical posted payment evidence.

Serialize transition against staging/finalization and await a cart-scoped server reset before opening the destination. Reuse an existing suitable reset endpoint if available; otherwise add one guarded by the existing session, cart scope, authorization, and mutation-lock patterns. Invalidate old payment-chain recovery and refresh finalize identity for the changed composition, without altering posted idempotency records. On reset failure stay in the current flow, report the error, and allow retry; never let a late request restore discarded payments. Reopening the same flow bypasses reset. A clean switch needs no warning but still clears obsolete flow metadata.

A browser-only clear was rejected because finalize can recover payments from session. Silent carryover was rejected because the user explicitly requires re-entry in either direction.

### Exact overflow-safe proportional arithmetic

Keep integer minor units and largest-remainder ordering. Compute quotient and remainder of the product/divisor using an overflow-safe integer multiply/divide helper (quotient/remainder decomposition with bounded additions and doubling), avoiding the oversized product entirely. Retain existing split-key ordering and tie breaks. Do not cast the overflowing float back to int or replace exact arithmetic with floating-point ratios. No new runtime arithmetic extension is required. Limit changes to affected proportional allocation paths; inspect analogous arithmetic reached by the new multiple-down-payment flow for the same defect.

### Focused verification

Extend PosCheckoutPaymentSplitServiceTest for the logged groups/payment, exact expected allocations, zero/small values, uneven remainders, and deterministic ties. Add focused debt integration coverage for these amounts and mixed-method multiple down payments, settlement totals, due amounts, cash events, and term propagation. Extend the staged-payment JavaScript fixture/tests for both switch directions, cancellation, dirty inputs, reset failure, same-flow recovery, and request exclusion. Verify permission combinations and layout with targeted rendering/visual checks. Run only relevant filters/files; no full-suite run is required.

## Risks / Trade-offs

- Late stage responses can restore cleared state → serialize transitions and bind responses to the active chain generation.
- Approval or evidence reuse after switching → clear flow-specific associations/tokens and reauthorize through existing policy.
- Arithmetic refactor changes rounding → assert exact existing allocations and tie behavior alongside the large-value regression.
- Hidden controls can remove entire columns → test full, partial, save-only, and no-action permission combinations.

## Migration Plan

No database migration or historical rewrite is planned. Deploy backend reset/flow compatibility and frontend assets together. Legacy session chains infer flow from is_debt when explicit identity is absent. Rollback application/assets together; preserved posting records remain canonical. Use focused smoke checks before release.
