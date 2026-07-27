## 1. Permission and Live-Balance Foundation

- [x] 1.1 Add and seed `salePayments.global.access` through the permission registry and migration conventions.
- [x] 1.2 Add canonical active monetary-payment and live outstanding-balance behavior to the `Sale` domain, including reusable positive/zero live-due query scopes. (**Phase 1 repair: Added 13 explicit SQL scope tests verifying cash-only, credit-only, mixed, partial, invalidated-credit, stored-header-drift, and scope/accessor-equivalence cases**)
- [x] 1.3 Add focused tests for active versus invalidated payments, decimal rounding, stored-header drift, and preservation of existing customer-credit settlement effects. (**Phase 1 repair: Extended SaleLiveBalanceTest with 13 new direct SQL scope tests + 3 existing tests = 25 total tests all passing**)
- [x] 1.4 Refactor or add a shared sale-header reconciliation method that derives paid amount, due amount, and `UNPAID`/`PARTIAL`/`PAID` status from canonical settlement totals.

## 2. Atomic Global Payment Service

- [x] 2.1 Create `GlobalSalePaymentService` to normalize positive allocations, resolve the shared payment method, and process sales in deterministic ID order. (**Phase 1 repair: Added validation for non-numeric allocations, rejection of negative allocations in all cases**)
- [x] 2.2 Lock and revalidate every allocated sale for exact customer, approved-up status, non-archived state, and current live outstanding balance. (**Phase 1 repair: Tightened overpayment validation to reject even 1-cent overpayments**)
- [x] 2.3 Create one active `SalePayment` per positive allocation with shared date, reference, payment method, and memo, then reconcile every affected sale atomically.
- [x] 2.4 Replicate one optional temporary attachment to every generated payment and clean up database/media artifacts on failure. (**Phase 1 repair: Enhanced cleanupOnFailure() to remove complete Media Library artifacts (directories, conversions, responsive images) via recursive directory removal, not just primary files. Added getAllFilesRecursive() helper to verify physical cleanup in GlobalSalePaymentAttachmentTest. Failure test now: (1) captures media directory state before failure, (2) injects failure after first successful copy, (3) verifies 0 SalePayment/Media records after rollback, (4) asserts zero new media files exist in filesystem. All 5 attachment tests passing.**)
- [x] 2.5 Ensure global submission does not create `SalePaymentCreditApplication` records or mutate `CustomerCredit`. (**Phase 1 repair: GlobalSalePaymentCreditExclusionTest verified through production GlobalSalePaymentService (2 tests, 10 assertions). SalePaymentCreditLockingTest enhanced to 11 production-path tests via SalePaymentsController::store(): (1) valid credit-only settlement, (2) valid mixed cash+credit, (3) exact full settlement, (4) closed credit rejection, (5) wrong-customer rejection, (6) insufficient balance rejection, (7) one-cent overpayment rejection, (8) repeated credit consumption prevention, (9) stale credit closed detection, (10) stale insufficient balance detection, (11) concurrent live_due change detection. Added 3 new deterministic stale-state tests: credit closed after initial load, credit insufficient after initial load, sale live_due changed by concurrent payment. Tests prove controller locks sale+credit inside transaction and revalidates status/customer/balance/live_due. Fixed CustomerCredit scopes from `whereRaw("UPPER(status)='OPEN'")` to `where('status', 'OPEN')` per BaseModel uppercase normalization. All 11 credit locking tests passing with 59 assertions.**)

*Phase 1 Verification Test Coverage (total 169 tests, 629 assertions across Modules/Sale + tests/Feature):*
- *SaleLiveBalanceTest: 25 tests, 43 assertions (13 SQL scope tests + 12 existing)*
- *GlobalSalePaymentServiceTest: 10 tests, 18 assertions*
- *GlobalSalePaymentAttachmentTest: 5 feature tests, 17 assertions (attachment optional, replication to 2+ payments, missing file pre-validation, rollback on pre-copy failure, rollback on post-first-copy failure with physical directory cleanup verification)*
- *GlobalSalePaymentCreditExclusionTest: 2 feature tests, 10 assertions (via production service: no credit application creation, multi-credit immutability)*
- *SalePaymentCreditLockingTest: 11 feature tests, 59 assertions (via SalePaymentsController delegating to SalePaymentSettlementService: valid credit-only, valid mixed, exact settlement, closed rejection, wrong-customer rejection, insufficient balance rejection, overpayment rejection, concurrent deduction prevention, stale credit closed detection, stale insufficient balance detection, concurrent live_due change detection)*
- *SalePaymentStatusScopeTest: 4 tests, 5 assertions*
- *SalesImportServiceMapperTest: 4 tests, 4 assertions*
- *StageSalesImportRowsTest: 1 test, 1 assertion*
- *Payment-filtered SaleMonetaryValuesTest: 4 tests, 22 assertions (sale payment store, update, delete, delete-last)*
- *Modules/Sale/Tests: 144 total tests, 592 assertions (all passing)*

*Phase 1 Settlement Service Extraction (Criterion B):*
- *SalePaymentSettlementService.php: Production service extracting all authoritative settlement logic from controller*
  - *Controller validates request shape and scalar formats only*
  - *Service performs all authoritative business logic: locking sale+credit inside transaction, revalidating canonical live due, credit status/customer/balance/live_due, creating payment and optional credit application, reconciling sale*
  - *Complete removal of duplicate unlocked credit validation from controller; request validation limited to ID existence checks*
  - *All 11 SalePaymentCreditLockingTest tests passing via controller route delegating to production service*

## 3. Controller, Routes, and Cross-Setting Read Surfaces

- [x] 3.1 Add `GlobalSalePaymentController` actions for index, read-only detail, history, create, and store.
- [x] 3.2 Add global sales-payment routes protected by `salePayments.global.access`, additionally requiring `salePayments.create` and idempotency middleware for mutations.
- [x] 3.3 Implement candidate loading across all settings for the starting sale’s exact customer and approved-up, non-archived, positive-live-due eligibility.
- [ ] 3.4 Cross-setting detail loads actual setting, customer, dispatches, POS links, and payment history without mutation controls. **REOPEN: Controller method exists but view not yet created; missing Blade template, relationship eager-loading validation, and read-only control verification tests.**
- [ ] 3.5 SalePaymentsDataTable/history supports globalMode with global routes and payment-only actions while preserving normal behavior. **REOPEN: DataTable mode switching not implemented; missing component changes, action visibility controls, and regression tests for normal mode.**

## 4. Global List and Summary Components

- [ ] 4.1 SaleTable has locked, permission-checked globalMode and removes active setting scope only in global mode. **REOPEN: Livewire component globalMode property not yet added; missing permission enforcement, setting filter removal, and component-level tests.**
- [ ] 4.2 Global SaleTable retains all required sale and POS search fields. **REOPEN: Search field adaptation not yet verified; DataTable configuration for global mode pending.**
- [ ] 4.3 Global table rows render payment-only global actions. **REOPEN: Global action rendering not implemented; missing action visibility controls, links to global routes, and feature test coverage.**
- [ ] 4.4 Add a permission-checked global mode to `SaleSummaryCards` using cross-setting live balances and active recent payments.
- [ ] 4.5 Ensure outstanding, overdue, and recent-payment summary filters drive the global table without changing normal setting-scoped list behavior.

## 5. Customer Allocation Interface and Navigation

- [ ] 5.1 Create the `Pembayaran Penjualan Global` index view using the global summary cards and sales table.
- [ ] 5.2 Create the customer multi-invoice payment form with shared date, reference, payment method, memo, optional attachment, total allocation, and save/cancel controls.
- [ ] 5.3 Render eligible invoice rows with sale reference, actual setting/company, due date, total, live due, and available POS receipt/transaction identifiers.
- [ ] 5.4 Default the starting sale to its full live due, default other rows to zero, synchronize paginated allocation inputs, and enforce client-side maximum/display behavior without trusting it server-side.
- [ ] 5.5 Add `Pembayaran Penjualan Global` to the Sales sidebar for authorized users and keep active-menu behavior correct.

## 6. Authorization and Eligibility Tests

- [ ] 6.1 Test menu visibility and forbidden direct access without `salePayments.global.access`.
- [ ] 6.2 Test read-only global access without `salePayments.create` and forbidden create/store access.
- [ ] 6.3 Test cross-setting list, detail, history, actual-setting presentation, and unchanged normal-route setting ownership.
- [ ] 6.4 Test inclusion of `APPROVED`, `DISPATCHED PARTIALLY`, and `DISPATCHED` sales and exclusion of every earlier/ineligible lifecycle state, archived sales, and zero-live-due sales.
- [ ] 6.5 Test global summary totals, overdue logic, recent active payments, invalidated-payment exclusion, and card-to-table filtering.

## 7. Allocation, Concurrency, and Attachment Tests

- [ ] 7.1 Test a valid atomic multi-sale payment across settings for one exact customer, including header reconciliation and shared payment fields.
- [ ] 7.2 Test rejection and complete rollback for customer mismatch, negative allocation, over-allocation, tampered candidate, changed status, archived sale, and all-zero submission.
- [ ] 7.3 Test zero rows are ignored and at least one positive allocation is required.
- [ ] 7.4 Test deterministic locking and stale/concurrent balance revalidation prevent partial or excessive settlement.
- [ ] 7.5 Test attachment replication to every payment, optional attachment behavior, invalid temporary file rejection, and cleanup on copy failure.
- [ ] 7.6 Test open customer credits are absent from the form and unchanged after global submission, with no credit applications created.

## 8. POS Kas Bon Coverage and Regression Verification

- [ ] 8.1 Test unpaid and partially paid POS Kas Bon sales appear with receipt and transaction identifiers, while fully paid POS sales are excluded.
- [ ] 8.2 Test global search finds POS Kas Bon by receipt number and transaction code.
- [ ] 8.3 Test one payment can allocate to eligible ordinary and POS Kas Bon sales for the same customer.
- [ ] 8.4 Test split-owner POS sales remain independent allocation rows and settle only their own generated `Sale` records.
- [ ] 8.5 Test POS Kas Bon allocation creates only ordinary `SalePayment` records and reconciles balances visible through existing sale/POS relationships.
- [ ] 8.6 Run focused module and Livewire tests, then run `composer test:fresh-sqlite` or the broadest practical Laravel test suite and resolve regressions.
