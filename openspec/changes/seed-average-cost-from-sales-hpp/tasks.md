## 1. Candidate and bucket resolution

- [x] 1.1 Add focused test fixtures for settings in the Tiga Nusa, Top IT, and REST/global buckets; stock-managed and non-stock-managed products; imported and non-imported sale-cost snapshots.
- [x] 1.2 Implement reusable command-local resolution of product cost buckets and target-setting fallback consistent with `product:normalize-purchase-prices`.
- [x] 1.3 Query only positive `HPP_SNAPSHOT_IMPORT` snapshots for stock-managed products and select the latest candidate per product/bucket by sale date, sale ID, and sale-detail ID.
- [x] 1.4 Add tests proving non-authoritative, zero-cost, non-stock-managed, and older/retry-ordered snapshots cannot become candidates.

## 2. Reconciliation command

- [x] 2.1 Add `product:seed-average-cost-from-sales-hpp` in `Modules/Product` with dry-run behavior by default and explicit `--write` mode.
- [x] 2.2 Implement dry-run reporting for considered/skipped products, selected source snapshots and dates, target buckets, and create/update/unchanged row counts without writes.
- [x] 2.3 Implement write-mode updates that change only `product_prices.average_purchase_price` and preserve existing purchase, selling, tier, and tax fields.
- [x] 2.4 Implement missing `product_prices` creation using the established normalization template/default metadata behavior.
- [x] 2.5 Add tests for own-bucket precedence, REST/global fallback for special settings, REST/global distribution to non-special settings, and unchanged targets without candidates.

## 3. Verification and operator handoff

- [x] 3.1 Add feature tests for dry-run immutability, explicit write persistence, deterministic same-date tie-breaking, and existing-row metadata preservation.
- [x] 3.2 Run the focused command test suite and the existing sales HPP snapshot-import and product purchase-price-normalization tests.
- [x] 3.3 Document the post-import operator sequence: complete/review HPP import, run command dry-run, review output, then rerun with `--write`.
