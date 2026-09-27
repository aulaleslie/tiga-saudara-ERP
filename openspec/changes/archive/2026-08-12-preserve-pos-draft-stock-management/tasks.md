## 1. Draft Snapshot Preservation

- [x] 1.1 Persist each POS cart line's normalized `stock_managed` value in POS transaction line metadata when saving or updating a draft.
- [x] 1.2 Restore the persisted stock-management value when hydrating a loaded POS draft back into the cart.
- [x] 1.3 Add the safe legacy fallback that resolves missing metadata from the current product and retains conservative stock-managed behavior when the product is unavailable.

## 2. Regression Coverage

- [x] 2.1 Add a focused draft save-and-load test proving a non-stock service line restores with `stock_managed = false`.
- [x] 2.2 Add checkout preflight or finalize coverage proving a loaded non-stock service without a stock record is not reported as `STOCK_UNAVAILABLE`.
- [x] 2.3 Add or extend coverage proving a loaded stock-managed product remains subject to existing stock validation.

## 3. Verification

- [x] 3.1 Run the focused POS draft and service-product test suites.
- [x] 3.2 Run the appropriate broader POS checkout test suite or `composer test:fresh-sqlite` if the focused suite exposes shared schema concerns.
