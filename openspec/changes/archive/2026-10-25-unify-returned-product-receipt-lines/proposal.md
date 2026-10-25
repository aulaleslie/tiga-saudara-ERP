# Proposal

## Why

After a partial return, current POS receipts divide the remaining charged amount by remaining quantity and expose cent allocations as different unit prices for the same product. Database verification of transaction 8611 confirms 11 remaining markers appear as 2 PCS at Rp7,081.81 and 9 PCS at Rp7,081.82 even though both the POS snapshot and linked Sale detail record a selling price of Rp7,083.33.

## What Changes

- Display the recorded transaction selling price for remaining purchase units, preserving stored precision and honoring proven later Sale price adjustments.
- Keep each existing customer-facing line together; remove artificial quantity splits caused by allocating rounding remainders into displayed unit prices.
- Preserve authoritative remaining charges and existing row-total currency formatting independently of displayed unit-price multiplication. For transaction 8611, display 11 PCS at Rp7,083.33 and a row total of Rp77,900 following the Rp7,100 refund.
- Preserve meaningful packing units, serials, return history, genuine price-adjustment explanations, and shared completed print/reprint behavior.
- Verify with focused regression tests only; no full-suite test run is planned.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `pos-receipt`: Recorded selling-price presentation independent of return-adjusted charges and internal rounding allocations.

## Impact

Primarily `Modules/Pos/Services/PosReceiptReprintProjectionService.php` and focused POS receipt tests, with the shared receipt view changed only if needed. No schema, dependency, route, sale, refund, payment, or checkout-snapshot mutations. Existing distinct genuine selling-price groups are not merged or averaged; unrelated product lines and bundle components are not collapsed.
