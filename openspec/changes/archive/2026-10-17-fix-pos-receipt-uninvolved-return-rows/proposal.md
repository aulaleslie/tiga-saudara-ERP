# Proposal

## Why

Transaction 6878's second completed return prints an uninvolved Acer laptop alongside the actual photo-paper cash return. The laptop is a persisted `resolution = none` context row with no linked Sales Return detail; listing it as a returned item misrepresents the event.

## What Changes

- Exclude `none` resolution rows from customer-facing receipt return-history items.
- Keep actual cash-return and product-replacement rows, their serial lineage, order, and settlement evidence.
- Add focused regression coverage for a replacement followed by an unrelated partial cash return containing a serialized context row.
- Verify the confirmed quantity baseline: five purchased minus one returned leaves four.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `pos-receipt`: Completed return history excludes uninvolved `none` resolution rows.

## Impact

The change targets `PosReceiptReprintProjectionService::buildReturnHistory` and existing receipt return-projection tests. Existing stored records must be handled without a migration or historical cleanup. Return submission, lifecycle execution, financial calculations, and quantities are outside the fix. Verification uses focused tests only; no full suite or `composer test:fresh-sqlite` gate.
