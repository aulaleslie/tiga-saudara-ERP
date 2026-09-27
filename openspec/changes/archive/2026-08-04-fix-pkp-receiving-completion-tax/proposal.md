## Why

Completing a partially received PKP purchase currently drops the tax amount from every retained line. The preview shows, and finalization persists, untaxed totals even though the source purchase has valid PKP tax data, producing incorrect payable and tax-report values.

## What Changes

- Preserve and proportionally recalculate persisted PKP line tax, taxable subtotal, and tax identity when a supplier-shortfall completion reduces a line to its approved received quantity.
- Use the same monetary reconstruction for the non-mutating preview and the atomic completion transaction so the reviewed result is the persisted result.
- Preserve existing non-PKP tax stripping and shortfall completion eligibility, payment, audit, and receiving-lock behavior.
- Add regression coverage for taxed, mixed-tax, tax-included, non-PKP, and rounding-sensitive partial completions.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `partial-purchase-receiving-completion`: Completion and preview must preserve proportionate persisted PKP tax values for retained purchase lines and headers.

## Impact

- Affected code: `Modules/Purchase/Services/PurchaseReceivingCompletionService.php` and focused Purchase module tests.
- Affected persisted fields: `purchase_details.tax_id`, `purchase_details.product_tax_amount`, `purchase_details.sub_total`, and purchase tax/header totals.
- No API, schema, permission, migration, or external dependency changes are expected.
