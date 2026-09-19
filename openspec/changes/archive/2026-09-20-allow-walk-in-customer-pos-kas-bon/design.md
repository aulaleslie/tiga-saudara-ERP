# Design

## Context

The cart customer resolver distinguishes an explicitly selected customer (`selected`) from the setting's configured default customer (`walk_in`), while providing a real `resolved_customer_id` for both. The staged-payment UI uses the resolved ID and therefore permits Kas Bon selection for either source. Finalization separately requires the resolution source to be `selected`, causing a walk-in transaction to fail only at the final request.

The existing finalizer already loads the resolved customer and rejects a missing or inactive record. The surrounding Kas Bon controls for payment terms, permissions, down payments, idempotency, posting, and split allocation must remain intact.

## Goals / Non-Goals

**Goals:**

- Make backend customer eligibility depend on a valid resolved active customer rather than how that customer was resolved.
- Keep frontend and backend eligibility consistent.
- Preserve explicit rejection for unresolved and inactive customers.
- Verify the changed boundary with focused POS debt checkout tests.

**Non-Goals:**

- Changing how the default walk-in customer is configured or resolved.
- Allowing Kas Bon without any resolved customer.
- Changing Kas Bon authorization, payment-term, balance, due-date, receivable, or split-posting rules.
- Running or requiring the full project test suite for this narrow change.

## Decisions

### Use resolved customer validity as the eligibility boundary

Remove the requirement that the customer resolution source equal `selected`. Retain the existing checks that a resolved customer ID exists and that its customer record is active.

This uses the resolver's canonical output and naturally admits both `selected` and `walk_in` sources. An alternative would be to enumerate allowed source strings, but that duplicates resolver semantics and could reject a future legitimate resolution source even when it resolves to an active customer.

### Keep the staged-payment UI behavior unchanged

The staged modal already receives customer availability from `resolved_customer_id`, so no UI logic change is needed. Avoiding an unnecessary frontend edit keeps this change centered on the inconsistent backend guard.

### Use focused feature verification

Extend the existing POS debt checkout feature coverage to prove the default walk-in success path and preserve the explicit-selected, unresolved, and inactive boundaries. Verification will run the focused debt checkout test file or narrower test filters; a full-suite run is outside this change's required completion criteria.

## Risks / Trade-offs

- [Walk-in receivables are less individually attributable than named-customer receivables] → This is the intended business policy; the generated Sale remains attached to the configured walk-in customer record and participates in existing receivable handling.
- [Removing a source check could admit future resolver sources] → Eligibility still requires a real active customer, matching the new rule that any customer kind may use Kas Bon.
- [Existing tests encode the former named-only policy] → Update only the affected assertions and add focused regression cases for each eligibility boundary.

## Migration Plan

No data or schema migration is required. Deploy the validation and test changes together. Rollback consists of restoring the previous source restriction; transactions already posted under the walk-in customer remain valid ordinary Kas Bon Sales and require no data rewrite.
