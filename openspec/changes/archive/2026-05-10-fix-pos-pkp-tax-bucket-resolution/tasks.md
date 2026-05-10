## 1. Tax Resolution Coverage

- [x] 1.1 Add a focused split planner test proving a PKP source owner with no explicit line/product/stock tax resolves to fallback `TAX:<id>` instead of `NON_TAX`.
- [x] 1.2 Add a focused allocation or checkout test proving `quantity_tax` allocations resolve to taxable split buckets even when line tax metadata is missing.
- [x] 1.3 Add or update coverage proving non-PKP owner allocations that do not consume `quantity_tax` remain `NON_TAX`.
- [x] 1.4 Add a POS bundled checkout regression test matching the observed TNC case: PKP parent and PKP bundle component without explicit line tax combine into one taxable TNC split group while the non-PKP component remains separate.

## 2. Allocation Snapshot Corrections

- [x] 2.1 Update POS stock allocation tax snapshots so PKP source allocations carry a resolvable tax candidate using product/product-price, stock, default, or latest tax fallback.
- [x] 2.2 Ensure allocations from `quantity_tax` set `tax_bucket_used = true` and carry a resolved tax policy snapshot even when `product_stocks.tax_id` is null.
- [x] 2.3 Ensure non-PKP allocations not using `quantity_tax` continue to carry non-tax snapshots regardless of product or stock tax candidates.

## 3. Split Planner Corrections

- [x] 3.1 Update `PosCheckoutSplitPlannerService` tax resolution so PKP source owner status makes the allocation taxable without requiring POS line `tax_id`.
- [x] 3.2 Update bundle component tax planning so PKP-owned component allocation revenue uses fallback tax when parent and component tax metadata are missing.
- [x] 3.3 Preserve existing split key shape `source_setting_id:source_location_id:tax_bucket` while ensuring erroneous PKP `NON_TAX` groups become `TAX:<id>` groups.
- [x] 3.4 Return an actionable checkout validation error if a PKP or `quantity_tax` allocation requires tax but no fallback tax exists.

## 4. Posting Persistence

- [x] 4.1 Ensure generated `sale_details.tax_id` and `product_tax_amount` use the planned effective tax for PKP and `quantity_tax` allocations.
- [x] 4.2 Ensure generated `sale_bundle_items.tax_id` and `tax_amount` use the planned effective tax for taxable bundled component allocations.
- [x] 4.3 Ensure generated `dispatch_details.tax_id`, stock bucket deduction, and inventory transaction tax/non-tax quantities remain aligned with the planned allocation bucket.
- [x] 4.4 Confirm payment allocation, receipt payload, `pos_checkout_sales`, and split total reconciliation remain unchanged except for corrected tax bucket grouping.

## 5. Verification

- [x] 5.1 Run focused POS split planner and allocation tests.
- [x] 5.2 Run focused POS checkout finalization tests covering bundled and non-bundled split posting.
- [x] 5.3 Run `php artisan test --filter=POSCheckout` or the smallest broader POS checkout suite needed to catch regressions.
- [ ] 5.4 Manually verify a refreshed POS checkout equivalent to POS #1 produces one taxable TNC Sale group and one non-tax TPI Sale group.
