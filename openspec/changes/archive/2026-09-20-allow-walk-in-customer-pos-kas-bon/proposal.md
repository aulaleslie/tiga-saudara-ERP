# Proposal

## Why

POS checkout already resolves the configured default walk-in customer to an active customer record, but Kas Bon finalization rejects it solely because its resolution source is `walk_in` rather than `selected`. This contradicts the desired counter workflow, where any resolved customer may transact using Kas Bon.

## What Changes

- Allow Kas Bon checkout when the cart resolves to any active customer, including the configured default walk-in customer.
- Continue rejecting Kas Bon checkout when no customer can be resolved or the resolved customer is inactive.
- Keep the existing payment-term, authorization, down-payment, due-date, split-posting, and receivable behavior unchanged.
- Align backend finalization with the staged-payment UI, which already recognizes a resolved walk-in customer.
- Add focused regression verification for walk-in Kas Bon checkout without requiring a full test-suite run.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `pos-debt-checkout`: Replace the named-customer-only restriction with a requirement that Kas Bon checkout accept any resolved active customer, including the configured default walk-in customer.

## Impact

- `Modules/Pos/Services/FinalizePosCheckoutService.php`: adjust the Kas Bon customer eligibility guard.
- `Modules/Pos/Tests/Feature/POSDebtCheckoutTest.php`: add or update focused coverage for walk-in, selected, unresolved, and inactive customer behavior.
- `openspec/specs/pos-debt-checkout/spec.md`: update the customer eligibility contract when the change is archived.
- No database schema, route, API payload, permission, or dependency changes are expected.
