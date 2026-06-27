## 1. Regression Coverage

- [x] 1.1 Add a focused test reproducing negative-stock replay followed by a later purchase, asserting no negative or overflowing `BACKFILL_RUNNING_AVERAGE` snapshot is written.
- [x] 1.2 Add a test proving purchase DPP backfill subtracts `product_discount_amount` in addition to tax.
- [x] 1.3 Add a test for receipt-prorated purchase events using discounted tax-exclusive DPP.
- [x] 1.4 Add a test for suspicious unit cost handling that reports context and continues write-mode processing.
- [x] 1.5 Add a test proving `--start`/`--end` replay prior events for opening state while only writing matching sale details.

## 2. Replay Correctness

- [x] 2.1 Extract or isolate backfill cost calculation helpers so purchase DPP, receipt prorating, and moving-average behavior are testable.
- [x] 2.2 Update purchase event cost calculation to use `sub_total - product_tax_amount - product_discount_amount`.
- [x] 2.3 Change moving-average replay so running quantity `<= 0` clears poisoned running value and lets the next valid purchase reseed the average basis.
- [x] 2.4 Add deterministic same-timestamp ordering for purchase/receipt, purchase return, and sale events.
- [x] 2.5 Fix filtered replay semantics so date filters scope writes and counts, not prior state-building events.

## 3. Guardrails and Audit Output

- [x] 3.1 Add configurable maximum reasonable unit cost with a default of `100000000`.
- [x] 3.2 Detect negative, non-finite, and over-threshold unit costs before writing snapshots.
- [x] 3.3 Record suspicious-cost warnings with product ID, product code, sale detail ID, sale date, running quantity, running value, and computed unit cost.
- [x] 3.4 Ensure write mode skips suspicious computed values and continues processing remaining eligible rows.
- [x] 3.5 Update command summary output to include suspicious-cost counts without removing existing warning categories.

## 4. Performance Improvements

- [x] 4.1 Remove unnecessary default eager loading and select only replay-required columns in the current command path.
- [x] 4.2 Avoid loading all product media/brand/category data during backfill; process only product IDs and `stock_managed`.
- [x] 4.3 Replace per-product timeline reconstruction with chunked or streamed event replay where practical. (Implemented chunked processing via `chunk(100)` for Product query)
- [x] 4.4 Persist valid snapshot updates in bounded batches while preserving force and non-force semantics.
- [x] 4.5 Add additive composite indexes needed by the replay queries after checking existing index coverage. (Existing foreign key indexes on `product_id` provide optimal coverage as queries are solely `product_id` scoped).

## 5. Verification and Operations

- [x] 5.1 Run focused tests for `BackfillSalesCostSnapshotsCommandTest`.
- [x] 5.2 Run a broader PHP test pass appropriate for the touched sale/purchase modules.
- [x] 5.3 Document the recommended operational sequence: dry-run, inspect suspicious rows, then rerun `--write --force` after a failed partial write.
- [x] 5.4 Verify command output remains usable for both dry-run and write mode on a non-trivial dataset.
