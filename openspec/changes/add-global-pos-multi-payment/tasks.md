# Tasks

## 1. Settlement Projection and Persistence Foundation

- [x] 1.1 Add global POS payment batch and allocation migrations with customer, shared payment context, actor, idempotency, POS transaction, Sale, Sale payment, amount, foreign-key, uniqueness, and lookup indexes; verify migrations run on focused SQLite migration tests.
- [x] 1.2 Add batch/allocation models and relationships without making them a monetary source of truth; verify focused model relationship and cascade/restriction tests pass.
- [x] 1.3 Implement one canonical reachable-Sales resolver supporting split mappings and inline fallback while detecting missing, duplicate, and inconsistent mappings; verify focused split, inline, and anomaly tests pass.
- [x] 1.4 Implement the query-efficient POS settlement projection for effective paid, live due, payment status, earliest outstanding due date, overdue amount, and qualifying completion state; verify focused projection tests cover paid, partial, zero-down unpaid, invalidated payment, credit, return, archived-child, and split-owner cases.

## 2. Priority Allocation and Atomic Payment Service

- [x] 2.1 Extract or adapt the established POS ownership/payment prioritization into a deterministic remaining-balance planner; verify focused unit tests prove checkout-compatible ordering, settled-Sale skipping, caps, remainder handling, and exact decimal reconciliation.
- [x] 2.2 Build POS-level allocation preview using locked-current-compatible projection inputs and expose child Sale, owner business, live due, and planned allocation; verify focused preview tests cover single, split, partial, and already-partly-settled transactions.
- [x] 2.3 Implement atomic same-customer multi-POS submission with deterministic locks, server-side revalidation, ordinary active `SalePayment` creation, Sale reconciliation, batch/allocation audit links, and overpayment rejection; verify focused service tests cover success, customer mismatch, changed balance, mapping mutation, concurrent replay, and rollback.
- [x] 2.4 Reuse attachment validation, replication, and rollback cleanup for every generated Sale payment; verify focused attachment success, optional, and partial-copy failure tests pass.
- [x] 2.5 Add request idempotency for global POS payment submissions and verify a replay returns the committed result without duplicate batches, allocations, or Sale payments.

## 3. Authorization, Routes, and Navigation

- [x] 3.1 Add dedicated global POS payment access, create, and history permissions to the established permission/role conventions; verify focused permission registration and role-matrix tests pass.
- [x] 3.2 Add global list, detail, history, create, preview, store, and receipt-reprint routes/controllers that do not depend on session setting; verify focused route tests cover authorized cross-setting access and direct-request denial.
- [x] 3.3 Add navigation visibility for the global POS payment workspace and verify it appears only with global POS payment access.
- [x] 3.4 Enforce separate create permission and combined global-access plus existing receipt-reprint permission checks; verify focused authorization tests cover view-only, create, history, and reprint combinations.

## 4. Global Register, Cards, and Filters

- [x] 4.1 Build the global POS register with one row per completed transaction having a posted checkout and reachable Sale, including paid, partial, and unpaid records while excluding draft, loaded, cancelled, incomplete, and no-Sale records; verify focused list eligibility and pagination tests pass.
- [x] 4.2 Add row fields for originating business, transaction/receipt identifiers, customer, completion date, checkout total, effective paid, live due, effective due date, payment status, cashier, terminal, and allowed actions; verify focused component/view tests assert values and action visibility.
- [x] 4.3 Add outstanding, overdue, and paid-within-30-days cards using distinct POS transaction counts and canonical totals; verify focused card tests cover split-row deduplication, boundary dates, future and invalidated payment exclusion, and zero-down debt.
- [x] 4.4 Add Sales-style business, customer, transaction-date, due-date, payment-status, cashier, terminal, and explicit card filters with durable selection/reset behavior; verify focused filter interaction and restoration tests pass.

## 5. Tokenized and Exact Search

- [x] 5.1 Extend the shared search support with POS-transaction tokenized search using AND across tokens and OR across transaction code, receipt, customer, POS note, Sale reference, historical/current product, bundle/component, payment reference, and business fields; verify focused cross-field token and missing-token tests pass.
- [x] 5.2 Add exact case-insensitive barcode lookup across captured POS, current primary, and conversion barcodes; verify focused exact, casing, historical, and partial-nonmatch tests pass.
- [x] 5.3 Add exact normalized serial lookup across generated Sale/dispatch provenance and POS snapshot fallback, including bundle components; verify focused modern, legacy, split-owner, casing, and partial-nonmatch tests pass.
- [x] 5.4 Ensure all search paths use existence/subqueries and preserve one POS row, pagination totals, and summary counts; verify focused duplicate-join regression tests pass.

## 6. Customer Multi-POS Form

- [x] 6.1 Build the payment form with read-only customer, shared payment fields, optional attachment, transaction allocations, totals, preview, cancel, and save controls; verify focused rendering and validation tests pass.
- [x] 6.2 Load only payable transactions for the exact starting customer across settings, force the selected transaction to the first row with full live-due default, and default other rows to zero; verify focused ordering and candidate-isolation tests pass.
- [x] 6.3 Render the priority-expanded child-Sale preview and surface stale or anomalous data errors without permitting submission; verify focused UI/component tests cover split ownership and changed balances.

## 7. Global Detail, History, and Receipt

- [x] 7.1 Build read-only global POS detail using the transaction's actual business context and eager-loaded transaction, checkout, products, bundles, serials, generated Sales, returns, payments, and print history; verify focused cross-setting detail and query-count tests pass.
- [x] 7.2 Display original checkout tender separately from later collections and show each generated Sale's business, total, effective paid, live due, status, and authorized global Sale link; verify focused detail presentation tests pass.
- [x] 7.3 Build POS-level history from checkout provenance, global POS audit mappings, and child-Sale settlement records, retaining invalidated entries and labeling known origins; verify focused history aggregation and invalidation tests pass.
- [x] 7.4 Enable global receipt reprinting through the existing receipt projection and print log using the originating business, with current settlement shown only in a clearly separate section if present; verify focused permission, historical-tender, later-payment, and print-log tests pass.

## 8. Focused Regression Verification

- [x] 8.1 Run focused tests for global POS projection, priority allocation, payment atomicity/idempotency, list/cards/filters/search, authorization, detail/history, and receipt reprinting; verify all targeted tests pass without scheduling the full application suite.
- [x] 8.2 Run focused existing Global Sales Payment, POS debt checkout, POS split posting/payment allocation, Sale live-balance, payment invalidation, and receipt tests; verify the affected regression set passes.
- [x] 8.3 Run `openspec validate add-global-pos-multi-payment --strict` and verify the completed change artifacts remain valid and implementation behavior is traceable to the specifications.
