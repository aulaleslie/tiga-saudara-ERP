# Design

## Context

See proposal.md for the reported symptom. Transaction 11364 is DRAFT: its first row has qty 20, unit_price 1750, conversion factor 10, and price_source LINE_UNIT_PRICE_OVERRIDE. Retained breakdown metadata records base_unit_label PCS and loose_count 20; packed prices in that old breakdown are not authoritative after override. The second row uses PACKED and correctly displays 50 PCS at Rp12,000.

PosReceiptService constructs unit breakdowns separately in getTransactionReceiptData and checkout receipt mapping. Both non-PACKED conversion branches divide unit_price by the factor and use the conversion label. PosReceiptReprintProjectionService reconstructs completed breakdowns with current Sale prices, but its non-PACKED branch also selects the conversion label. Existing tests include POSTransactionReceiptReprintTest, POSReceiptGenerationTest, POSReceiptReturnProjectionTest, and POSReceiptNotesAndPriceAdjustmentTest.

## Goals / Non-Goals

**Goals:** Keep price, quantity, and label on the same basis in all affected receipt mappings with focused regressions.

**Non-Goals:** Change packing arithmetic, override execution, checkout posting, totals reconciliation, return behavior, or historical financial records. Row-total override behavior is outside this narrowly scoped correction.

## Decisions

1. Handle LINE_UNIT_PRICE_OVERRIDE explicitly before ordinary conversion formatting. Its stored unit_price is already Rupiah per recorded quantity unit; use it directly. Keep PACKED processing intact. Removing division for all conversion rows was rejected because it would alter unrelated pricing contracts.
2. Resolve override base labels from recorded breakdown.base_unit_label, then pricing_basis.base_unit_label; fall back to the related conversion base unit or product base unit using existing relation conventions. Retained conversion_id alone does not prove pack-based quantity. Reuse a small receipt formatting/label helper where practical across both snapshot mappings and the projection; no new pricing service is needed.
3. In completed projection, retain its proven current Sale price selection and effective quantity, changing override unit labeling consistently. Do not reuse original override price over a legitimate later Sale edit, or derive a replacement price from subtotal/quantity.
4. Use focused Laravel feature tests with synthetic fixtures matching transaction 11364, rather than depending on local production data. Cover both snapshot builders and completed projection. Assert receipt data and rendered text where existing helpers support it, including unchanged amounts. Run the affected test methods/classes only; broaden verification only if failures identify related behavior.

## Risks / Trade-offs

- [Old packed breakdown contains stale prices] → Use metadata only for unit labels on override rows; use authoritative snapshot or projected prices.
- [Three formatting sites drift] → Share label resolution where practical and test draft, checkout snapshot/historical, and completed paths.
- [Missing label snapshots] → Exercise model-based base-unit fallback without interpreting the retained conversion label as the quantity unit.
- [Completed Sale price changes are accidentally masked] → Include a completed override fixture whose linked Sale price differs from checkout and assert current versus historical authority.

## Migration Plan

No migration or data repair. Deploy the receipt formatting change after focused tests pass. Revert the code change to roll back; persisted transactions remain unchanged. Avoid opening live print routes during verification because they create print audit records.
