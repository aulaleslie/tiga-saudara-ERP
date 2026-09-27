# Tasks

## 1. Fix hydration

- [x] 1.1 In `Modules/Pos/Services/PosTransactionSnapshotMapper.php::hydrateCart()`, change the `available_qty` assignment (~line 259) to return `null` when `$stockManaged` is `false`, and the existing computed int when `$stockManaged` is `true` — verify by reading the diff matches `PosCartService::resolveCartProduct()`'s null-for-non-stock behavior.

## 2. Focused verification

- [x] 2.1 Add/extend a focused test (e.g. in the existing draft-hydration test file covering `pos-draft-stock-management-preservation`, or `PosTransactionSnapshotMapper`/`PosTransactionService` draft-load tests) that hydrates a draft with one stock-managed line and one non-stock-managed line, and asserts the non-stock line's `available_qty` is `null` after hydration.
- [x] 2.2 Extend the same or a companion test to perform a quantity update on the reloaded non-stock line via `PosCartService::updateLine` (or the equivalent cart-update entry point) and assert it succeeds without throwing the "exceeds available stock" error — verify by running only this test file/filter (e.g. `php artisan test --filter=<TestClassName>`), not the full suite.
- [x] 2.3 Manually confirm no regression on stock-managed lines: run the existing `pos-draft-stock-management-preservation` test coverage for stock-managed drafts (filtered run) and confirm it still passes unchanged.
