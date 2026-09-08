## 1. Capture authoritative amounts

- [x] 1.1 Add a focused draft-save regression fixture reproducing 3393: raw affected row 25992, rounded row 26000, saved total 318000; assert persisted metadata and reload behavior.
- [x] 1.2 Trace draft save, completion, mapper, and loaded-cart contracts; define explicit gross, row-discount, pre-bill net, bill allocation, tax, and charged amount precedence without changing override detection.
- [x] 1.3 Persist calculated lines and headers from the same snapshot in draft and completion paths, preserving source metadata, serials, bundles, and snapshot hashes atomically.
- [x] 1.4 Verify stable reload/save after increment changes, packed automatic rows, manual unit/total overrides, and disabled rounding.

## 2. Align monetary presentation

- [x] 2.1 Implement shared persisted row-amount resolution with explicit minor-unit precedence and stable legacy fallback; use it in transaction detail and receipt mapping.
- [x] 2.2 Present authoritative row totals and discounts without rounding wording or a separate rounding adjustment row; retain internal rounding reconciliation without subtracting discounts twice.
- [x] 2.3 Preserve nonzero monetary decimals in receipts and transaction lists using existing locale conventions; verify existing POS and detail decimal support.
- [x] 2.4 Add focused non-browser receipt/detail amount-resolution and server-rendered content tests for automatic, packed, manual, row-discount, and bill-discount cases, including large and fractional amounts.

## 3. Verify downstream reconciliation

- [x] 3.1 Exercise rounded checkout through owner splits, bundle allocations, generated sales, multiple payments, cash change, outstanding debt, and report totals; correct consumers that reconstruct captured charges.
- [x] 3.2 Verify taxable row amounts and global discounts reconcile in minor units without additional increment rounding.
- [x] 3.3 Test successive partial returns against rounded source values across a setting change and verify remainder exhaustion equals the original returnable amount.

## 4. Provide explicit draft repair

- [x] 4.1 Implement an explicitly ID-selected, setting-scoped repair preview that uses captured pricing evidence, states the increment, and refuses ambiguous or header-mismatched recovery.
- [x] 4.2 Implement explicit apply with actor attribution, status/active-cart guards, row locking, preview hash revalidation, before/after audit evidence, atomic snapshot/hash refresh, and idempotency.
- [x] 4.3 Test the 3393 fixture repair, unchanged header, repeated apply, wrong setting, ambiguous data, changed hash, active/loaded state, completed/cancelled rejection, and rollback on failure.
- [x] 4.4 Document preview/apply usage, eligibility limitations, audit recovery, and deployment/rollback procedures; do not apply real-data repairs as part of implementation.

## 5. Validate the change

- [x] 5.1 Run only explicitly selected Laravel test files or filters for affected persistence, presentation, checkout allocation, reports, returns, and repair using an isolated test database; record results. Do not run or require the full suite, unfiltered `php artisan test`, or `composer test:fresh-sqlite`.
- [x] 5.2 Review the final diff against the capability scenarios and verify application deployment causes no historical data rewrite; run OpenSpec validation and record any remaining limitations.
- [x] 5.3 Prepare a human-only browser verification checklist for POS decimals, detail/receipt agreement, and compact receipt print layout with large and fractional amounts. Do not perform browser testing or browser automation; mark execution pending human verification until human results are available.
