## Why

Opening a supplier detail page fails with an `Attempt to read property "name" on null` error when the supplier has no payment term. This is valid data under the current nullable foreign-key and supplier form contract, so the detail page must represent the missing value without failing.

## What Changes

- Render supplier details successfully when `payment_term_id` is null or its relationship cannot be resolved.
- Display a clear placeholder for a supplier without a payment term.
- Preserve the existing payment-term name display for suppliers with a valid related payment term.
- Add regression coverage for supplier detail pages with and without a payment term.

## Capabilities

### New Capabilities

- `supplier-detail-display`: Defines reliable supplier detail rendering for optional related master data, including payment terms.

### Modified Capabilities

None.

## Impact

- Affects the People module supplier detail Blade view.
- Adds focused feature coverage for the supplier show route and optional payment-term relationship.
- Does not change the database schema, supplier creation/update contract, routes, APIs, or dependencies.
