## 1. Cross-setting price initialization

- [x] 1.1 Load all available settings dynamically during each valid combined snapshot target mutation without hardcoding owner names or setting IDs.
- [x] 1.2 Update or create the resolved owner's selling-tier row from the imported price, then create missing `product_prices` rows for the same product in every other setting using that imported selling price.
- [x] 1.3 Preserve every existing non-owner price row and keep cross-setting seeding, owner stock mutation, product aggregate update, and ADJ transaction in one database transaction.

## 2. Verification

- [ ] 2.1 Add tests with at least five settings proving the first owner row seeds all missing settings while preserving any existing other-setting price.
- [ ] 2.2 Add sequential owner-row tests proving later Top IT and Perdana rows update only their respective owner prices after initial cross-setting seeding.
- [ ] 2.3 Add a persistence-failure test proving a cross-setting seeding failure rolls back owner price, seeded price rows, stock, aggregate quantity, and ADJ transaction while unrelated targets can still complete.
- [ ] 2.4 Run focused combined price-and-stock snapshot and existing price snapshot test suites.
