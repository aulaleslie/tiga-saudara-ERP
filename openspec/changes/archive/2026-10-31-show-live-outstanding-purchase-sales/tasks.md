# Tasks

## 1. Canonical live balance query support

- [x] 1.1 Add reusable purchase and sales settlement/live-due query projections following existing live-due scopes and accessor semantics, including a preloaded sales credit sum where needed; add focused model/query tests proving projection/accessor parity for no payment, partial payment, active sales credit, invalidated payment/credit, fractional currency and over-settlement; verify the new cases with `php artisan test --filter=<balance-test-class>` on isolated SQLite.
- [x] 1.2 Verify projected zero-clamped balances support SQL predicates and deterministic ordering without changing stored records; include intentionally stale header values and opposite stored/live ordering in focused tests, and document the projection's canonical units and fallback behavior beside the model helper.

## 2. Consistent list rows and balance controls

- [x] 2.1 Update PurchaseTable and SaleTable queries and row templates to use preloaded live balances in both modes, with outstanding/overdue predicates and existing due sorting applied before pagination; extend focused normal/global Livewire tests for stale zero/positive headers, active credit and invalidated payments, and verify displayed row amounts and filter membership using `php artisan test --filter=<affected-table-test-class>`.
- [x] 2.2 Add a multi-page purchase sort case in both directions with equal-zero and equal-positive balance ties; verify ordering follows displayed live amounts across pages while preserving existing identifier tie-breaks and search/business/supplier/customer constraints; run the focused sort/filter tests.
- [x] 2.3 Verify settlement query loading stays bounded as page rows increase using a targeted query-count regression for both tables; verify no per-row payment/credit sum queries and record exact focused commands/results in this change's `verification.md`.

## 3. Outstanding in purchase and sales detail summaries

- [x] 3.1 Add Sisa Pembayaran beneath Total Keseluruhan in the shared purchase detail view and Sisa Tagihan in the shared sales detail view, using live balances and existing formatters; add focused normal/global route tests for partial and fully settled documents with stale headers, verify the balance is visible without payment-create permission, and run those cases with `php artisan test --filter=<detail-balance-test-class>`.
- [x] 3.2 Assert normal/global detail and row parity with unchanged settlement evidence, and assert GET/render does not change stored headers, payments, credits or totals; verify unauthorized normal cross-business access remains rejected and existing authorized global access is preserved with targeted route tests, recording results in `verification.md`.

## 4. Focused integration checks

- [x] 4.1 Run affected existing global purchase/sales table, filter/order, embedded workspace isolation and sales-credit/invalidation regressions with targeted `php artisan test --filter=...` commands; record exact results and any fixture updates caused by deliberately moving normal displays from header values to active settlement in `verification.md`.
- [x] 4.2 Confirm the detail labels/zero formatting and list/detail amount parity on representative normal and Global Payment pages, recording any unavailable browser check honestly; run `openspec validate show-live-outstanding-purchase-sales --strict` and verify only intended implementation and change artifacts are modified.
