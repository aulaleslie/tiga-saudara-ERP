## 1. Cost Event Basis

- [x] 1.1 Add focused test coverage proving taxable purchase details normalize both `average_purchase_price` and `last_purchase_price` from DPP, not tax-included unit price
- [x] 1.2 Update `NormalizeProductPurchasePricesCommand` to calculate eligible unit cost from `sub_total - product_tax_amount` divided by purchase detail quantity
- [x] 1.3 Ensure approved received-note quantities and fallback purchase detail quantities still drive eligible quantity exactly as before

## 2. Bucketed Normalization

- [x] 2.1 Add test coverage for a product purchased by `CV TIGA NUSA COMPUTER`, `CV TOP IT INTERNUSA`, and another setting with distinct DPP costs
- [x] 2.2 Resolve special settings by case-insensitive company name matching for `CV TIGA NUSA COMPUTER` and `CV TOP IT INTERNUSA`
- [x] 2.3 Refactor normalization calculation to build per-product bucket summaries for Tiga Nusa, Top IT, and REST/global
- [x] 2.4 Calculate weighted average and latest DPP last purchase price independently per bucket

## 3. Write Targets and Fallback

- [x] 3.1 Add test coverage that Tiga Nusa and Top IT rows receive their isolated bucket results when their buckets have eligible history
- [x] 3.2 Add test coverage that non-special settings receive the REST/global bucket result
- [x] 3.3 Add test coverage that Tiga Nusa and Top IT rows fall back to REST/global when their own bucket has no eligible history
- [x] 3.4 Update row creation/update logic so each setting row receives its target bucket result while preserving existing sales metadata
- [x] 3.5 Preserve dry-run behavior and row created/updated/unchanged counts under bucketed target selection

## 4. Regression Coverage

- [x] 4.1 Update existing normalization tests that assumed one global purchase cost for every setting
- [x] 4.2 Keep or add coverage that products without eligible cost in any bucket are skipped
- [x] 4.3 Keep or add coverage that missing rows still copy same-product sales metadata or default sales metadata when no template exists
- [x] 4.4 Verify purchase approval/runtime global average synchronization remains unchanged

## 5. Verification

- [x] 5.1 Run focused normalization command tests
- [x] 5.2 Run focused purchase approval price sync test
- [x] 5.3 Run `openspec status --change normalize-product-purchase-price-buckets` and confirm the change remains apply-ready
