# Proposal

## Why

Opening a Bayar Kas Bon review currently adds CASH for the full outstanding balance automatically. Cashiers should explicitly choose the payment method before recording a collection.

## What Changes

- Start every Kas Bon payment review with no selected payment methods and a blank method picker.
- Display Rp0 total payment, the full live outstanding debt, and a disabled submit button until a valid payment is entered.
- Preserve the existing explicit add-method behavior, including allocating the remaining due when the cashier adds a method.
- Update the existing JavaScript regression test and use focused verification only.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `pos-kas-bon-collection`: Require explicit payment method selection for each newly opened transaction review.

## Impact

- Client form initialization in `public/js/pos-kas-bon-collection.js`.
- Existing DOM regression coverage in `tests/js/pos-kas-bon-collection-state.test.cjs`.
- No database, endpoint, settlement, or checkout-flow changes are required. Verification is limited to the affected JavaScript regression and a short manual form check; no full suite is required.
