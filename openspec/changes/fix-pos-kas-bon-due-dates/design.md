## Context

Kas Bon is collected through the POS staged-payment modal. Its debt flag and selected payment term are stored in the payment-chain session state, then sent again by the final checkout request. Finalization persists those values in checkout metadata and passes them to either direct or split sale posting. The sale-posting adapter is responsible for assigning `payment_term_id` and calculating `due_date`.

The defect is observable on generated Sales documents, including a checkout that produces multiple source-owner documents. The existing direct debt-finalize test proves date arithmetic but does not prove the complete staged session handoff for every generated sale.

## Goals / Non-Goals

**Goals:**

- Treat the selected Kas Bon payment term as authoritative checkout context through staged payment, finalization, and all sale-posting groups.
- Calculate every generated debt Sale's due date from one checkout posting date and the selected term longevity.
- Add regressions that exercise zero and partial down payment, including split-owner sales.

**Non-Goals:**

- Changing payment-term administration, customer defaults, receivable settlement, or non-debt POS checkout behavior.
- Updating historical sales documents or adding schema fields.

## Decisions

### Preserve the payment term in the existing checkout context

Use the established payment-chain request/session fields and immutable checkout metadata rather than deriving a term from the customer or payment-method label. The cashier explicitly selects the Kas Bon term; that selection must remain the source of truth.

Alternative considered: resolve the customer's configured payment term at posting time. Rejected because it can differ from the term explicitly selected for this checkout and may change after staging.

### Calculate one effective due date for the checkout and propagate it to sale groups

Resolve the selected `PaymentTerm` once from the final debt context, calculate the due date from the checkout posting date plus `longevity`, and ensure every sale group receives that same debt context. This prevents owner splitting from changing the term or date.

Alternative considered: let each inline adapter independently call the clock and resolve the term. Rejected because it duplicates critical logic and can create inconsistent documents around date boundaries.

### Verify the browser-equivalent staged flow at feature level

Add feature tests that stage or recover the Kas Bon payment chain, finalize it, and inspect all resulting Sales records. Include zero DP and partial DP paths plus at least one multi-owner/split result.

Alternative considered: retain only direct service tests. Rejected because the suspected failure boundary is session/request handoff, which direct adapter tests bypass.

## Risks / Trade-offs

- [Existing staged chains may contain incomplete debt metadata] → Finalization must reject debt context lacking a valid term instead of silently defaulting its due date.
- [Split posting has several payment-allocation paths] → Assert term/date invariants independently from paid/due allocation assertions in every created sale.
- [Date-dependent assertions can be flaky] → Freeze the checkout clock in tests and compare normalized date-only values.

## Migration Plan

Deploy as an application-code and test-only change. No migration or historical-data update is required. Roll back by reverting the application change; existing completed sales remain untouched.

## Open Questions

- None. The selected payment term, not the customer default, is the confirmed source of truth.
