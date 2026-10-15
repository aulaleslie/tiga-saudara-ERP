# Proposal

## Why

Purchase editing mixes base-unit discounts with entered-unit prices and normalization can convert the discount again, inflating recalculated subtotals and HPP. Purchase details and print also misinterpret percentage inputs as currency and the taxed details table subtracts the row discount twice from DPP.

## What Changes

- Preserve fixed discount per entered unit and percentage input through create, save, detail display, edit hydration, recalculation, and receiving approval.
- Normalize entered discounts into base-unit currency exactly once using authoritative conversion snapshots.
- Render fixed and percentage discounts with explicit semantics on purchase details and print; calculate monetary equivalents from the appropriate unit context.
- Display row DPP as subtotal minus row tax, without another discount subtraction.
- Preserve supported fractional quantities when recalculating discounted purchase rows.
- Verify the flow with focused regression tests for base/conversion units and non-PKP, PKP tax-included, and PKP tax-excluded purchases. No full-suite test task is planned.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `purchase-conversion-unit-entry`: Specify stable discount representations, reload/recalculation behavior, discount display, decimal quantity calculations, and discount-aware HPP consistency within the existing purchase unit flow.

## Impact

Affected areas include `PurchaseNormalizer`, purchase `ProductCart`, `EditForm`, controller/duplicate hydration where applicable, `PurchaseDetail` presentation helpers, purchase details/print templates, and focused purchase tests. Existing manual-total authority, tax policy, authorization, receiving lifecycle, precision conventions, and the HPP formula remain in force. No schema change or automatic historical data repair is planned; imports, sales/POS behavior, global discount allocation, and stock replay redesign are outside scope.
