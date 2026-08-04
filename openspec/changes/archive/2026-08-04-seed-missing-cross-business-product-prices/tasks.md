## 1. Cross-setting price initialization

- [x] 1.1 Load all available settings dynamically during each valid combined snapshot target mutation without hardcoding owner names or setting IDs.
- [x] 1.2 Update or create the resolved owner's selling-tier row from the imported price, then create missing `product_prices` rows for the same product in every other setting using that imported selling price.
- [x] 1.3 Preserve every existing non-owner price row and keep cross-setting seeding, owner stock mutation, product aggregate update, and ADJ transaction in one database transaction.

## 2. Verification

- [x] 2.1 Add tests with at least five settings proving the first owner row seeds all missing settings while preserving any existing other-setting price.
- [x] 2.2 Add sequential owner-row tests proving later Top IT and Perdana rows update only their respective owner prices after initial cross-setting seeding.
- [x] 2.3 Add a persistence-failure test proving a cross-setting seeding failure rolls back owner price, seeded price rows, stock, aggregate quantity, and ADJ transaction while unrelated targets can still complete.
- [x] 2.4 Run focused combined price-and-stock snapshot and existing price snapshot test suites.

## 3. Test Fixes Applied

- [x] 3.1 Replace fake rollback test with real failure test using ProductPrice::creating listener to inject controlled exception during cross-setting seeding (PERDANA setting).
- [x] 3.2 Correct Top IT source names: TP Monitor → Monitor TP, TP Mouse → Mouse TP (trailing TP suffix).
- [x] 3.3 Remove all conditional test bypasses: assert all rows import, exact batch counts, and every setting remains at prior values.
- [x] 3.4 Strengthen multi-product coverage: add Perdana follow-up test and verify independent price updates across all settings.
- [x] 3.5 Add mixed-outcome test: two independent products in same batch, failure on Product A's cross-setting seeding to TIGA COMPUTER, Product B completes normally, assert exact rollback/completion outcomes.
