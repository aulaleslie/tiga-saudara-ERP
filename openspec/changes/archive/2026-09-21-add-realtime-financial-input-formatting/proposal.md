# Proposal

## Why

Editable nominal and payment fields currently switch between a raw focused value and a formatted blurred value, so operators do not see the final Indonesian number grouping while they type. Financial input should instead format immediately, accept a dot as the operator-facing decimal key, and retain an exact canonical decimal value for calculations and submission.

## What Changes

- Format editable nominal values continuously as the operator types, using `.` for Indonesian thousands grouping and `,` for the displayed decimal separator, without embedding a currency symbol in the editable text.
- Accept only digits and at most one operator-entered `.` decimal separator; silently ignore any second decimal point or other character without clearing, resetting, or visibly invalidating the last accepted value.
- Preserve incomplete but valid editing states such as `120000.` as the display `120.000,` so the operator can continue entering a fractional amount.
- Keep display and canonical representations separate, so `120.000,23` is calculated and submitted as `120000.23`.
- Preserve natural editing for caret movement, selection replacement, Backspace, Delete, paste, and mobile decimal keyboards while grouping separators are added in real time.
- Apply the real-time behavior to the reusable nominal-field surface and the Sales, Purchase, and POS payment amount fields introduced by the latest payment-formatting change.
- Replace the current raw-on-focus/format-on-blur contract for affected fields; no payment, pricing, authorization, balance, or persistence rules change.
- Verify the formatter and affected integrations with focused JavaScript and feature/view tests only; a full test-suite run is outside this change.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `nominal-field-formatter`: Replace focus/blur-only nominal formatting with silent, real-time Indonesian formatting backed by an exact canonical value.
- `payment-amount-input-formatting`: Make the existing Sales, Purchase, and POS payment amount fields use the same real-time formatting, character gate, and canonical-value contract.

## Impact

- Affected shared UI code includes the nominal Blade component and `public/js/payment-amount-input.js` or its generalized replacement.
- Affected integrations include product nominal fields that consume the shared nominal component and the five existing single/global Sales, Purchase, and POS payment creation views.
- Focused JavaScript tests must cover typing, decimals, silent rejection, deletion, selection, paste, caret preservation, and canonical values; focused Laravel tests must cover markup and canonical submission integration.
- Controllers, services, routes, database schemas, monetary business rules, and stored values remain unchanged.
