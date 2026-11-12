# Tasks

## 1. Snapshot receipt price and unit correction

- [x] 1.1 Handle LINE_UNIT_PRICE_OVERRIDE explicitly in both PosReceiptService mappings, sharing base-label resolution where practical; add focused draft/loaded and checkout snapshot/historical regressions for qty 20, price Rp1,750, factor 10, and total Rp35,000. Verify receipt data and rendered output show PCS at Rp1,750, not PACK at Rp175, with unchanged amounts.
- [x] 1.2 Add missing-label fallback and ordinary PACKED controls alongside the snapshot regressions. Verify a model base-unit fallback renders correctly and whole-pack/loose breakdown prices and totals retain existing behavior using focused test filters.

## 2. Completed projection consistency

- [x] 2.1 Align override unit labels in PosReceiptReprintProjectionService with the recorded base quantity while preserving current Sale-derived prices; add completed print/reprint coverage with a proven later Sale price edit. Verify the current receipt shows the edited price in PCS and the explicit historical receipt retains Rp1,750, without rewriting snapshots or amounts.

## 3. Focused integration verification

- [x] 3.1 Run the new regression filters, then the affected existing receipt classes using isolated SQLite test configuration: POSTransactionReceiptReprintTest, POSReceiptGenerationTest, POSReceiptReturnProjectionTest, and POSReceiptNotesAndPriceAdjustmentTest. Record commands and results; broaden only for failures or newly affected behavior. Confirm the diff contains only receipt formatting and relevant tests, with no financial or schema mutations.

## Verification Notes

- Label resolution validates each recorded candidate (`breakdown.base_unit_label`, then `pricing_basis.base_unit_label`) individually; a blank primary no longer masks a valid secondary.
- `composer test:fresh-sqlite -- --filter='POSReceiptOverrideUnitPriceTest|POSTransactionReceiptReprintTest|POSReceiptGenerationTest|POSReceiptReturnProjectionTest|POSReceiptNotesAndPriceAdjustmentTest'` → 85 passed, 1 failed. The new class has 6 override tests plus 3 tests inherited from PosLineageSchemaAndCheckoutTest.
- The failure, `test_receipt_shows_correct_multi_payment_nominals_and_unit_breakdown` (missing "Harga sudah termasuk PPN"), also fails under fresh SQLite with this change's service edits stashed, so it predates this change and is unrelated.
- With the service edits stashed, the override regressions fail, confirming coverage.
- Diff limited to PosReceiptService, PosReceiptReprintProjectionService (label only), and the new test class; no schema or financial mutations.
