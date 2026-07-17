## 1. Permissions and Route Boundaries

- [x] 1.1 Add `purchasePayments.global.access` to the canonical purchase-payment permission catalog and permission synchronization/migration path without changing existing role assignments implicitly.
- [x] 1.2 Add named global purchase-payment list, read-only detail/payment-history, create-form, and submit routes before the existing parameterized purchase resource routes, enforcing global access on reads and both global access plus `purchasePayments.create` on writes.
- [x] 1.3 Add authorization tests proving the menu and all global routes are inaccessible without global access, read routes work without create permission, and payment creation remains forbidden without `purchasePayments.create`.
- [x] 1.4 Add regression coverage proving the normal purchase detail and existing single-purchase payment routes continue enforcing the active `setting_id` guard.

## 2. Operational Global Payment List

- [x] 2.1 Extend or wrap the operational purchase index/table with an explicit global-payment mode that never falls back to `session('setting_id')` and does not affect receiving or other embedded purchase-table contexts.
- [x] 2.2 Implement the global list query across all settings using exact `RECEIVED`, non-archived, positive live outstanding-balance eligibility and the established active purchase-payment amount scaling/semantics.
- [x] 2.3 Reuse the `Semua Pembelian` columns, search, sorting, pagination, status display, payment display, and layout while removing create/import controls and introducing a payment-only row action presentation.
- [x] 2.4 Add `Pembayaran Pembelian Global` beside `Semua Pembelian` under the operational `Pembelian` menu, gated by `purchasePayments.global.access` and kept separate from the existing global purchase report under `Laporan`.
- [x] 2.5 Add Livewire/feature coverage for cross-setting visibility, active-setting independence, exact-status and outstanding-balance filtering, archived exclusion, layout behavior, and absence of create/update/delete/approval/receiving/duplicate/archive actions.

## 3. Global Read-Only Purchase Detail

- [x] 3.1 Extract or share the current purchase-detail data-loading logic so a dedicated authorized global controller can load purchase, supplier, receiving/serial context, and payment history without calling the normal setting guard.
- [x] 3.2 Render the existing purchase detail through an explicit global read-only payment context that hides every non-payment mutation and uses dedicated global URLs for payment history and payment creation.
- [x] 3.3 Implement the global payment-history data endpoint/query without active-setting scope while preserving existing payment status, attachment, and invalidation presentation and exposing no unauthorized mutation.
- [x] 3.4 Update global list transaction/detail links to use the dedicated global detail route and direct eligible create-payment actions to the supplier multi-payment form.
- [x] 3.5 Add feature tests proving an authorized user can inspect a purchase and its payments from another setting, cannot access unrelated mutations from that context, and cannot use the global route to view an ineligible/unauthorized resource outside the defined workspace.

## 4. Supplier Allocation Form and Candidate Query

- [x] 4.1 Create a server-side candidate query/service that validates the starting purchase and loads purchases with the exact same `supplier_id`, exact `RECEIVED` status, non-archived state, and positive live outstanding balance without `setting_id` scope.
- [x] 4.2 Build the Bootstrap/CoreUI multi-payment page following the sample hierarchy with read-only supplier, date, reference, payment method, invoice allocation table, memo, one attachment, running subtotal/total, cancel, and save controls.
- [x] 4.3 Render transaction number, description, due date, total, live outstanding balance, and amount per invoice; initialize the starting invoice to its full balance and every other invoice to zero.
- [x] 4.4 Omit unsupported tags, withholding, separate payment due date, separate persisted account selection, and multiple-attachment controls while optionally displaying the selected payment method's chart-of-account as read-only context.
- [x] 4.5 Add component/feature tests for sample-inspired supported fields, omitted unsupported fields, exact-supplier cross-setting candidates, starting/default amounts, totals, and rejection of an ineligible starting purchase.

## 5. Atomic Multi-Purchase Payment Orchestration

- [x] 5.1 Create a dedicated multi-purchase payment service (e.g., `GlobalPurchasePaymentService`) for atomic transaction handling.
- [x] 5.2 Validate cross-setting allocations ensuring individual allocations do not exceed live outstanding balances and positive amounts are processed.
- [x] 5.3 Execute standard payment orchestration in a single database transaction, ignoring original `setting_id` scopes but ensuring precise `supplier_id` match.
- [x] 5.4 Use the unified global payment date, reference, payment method, memo, and shared attachment across each created payment while generating unique transaction references for each if necessary.
- [x] 5.5 Update balances for all participating purchases inside the same transaction.
- [x] 5.6 Write component/integration tests for valid multi-purchase atomic store, rejection of mismatched `supplier_id`, rollback on partial failure, allocation exceeding balance, and exact zero/negative allocations.

## 6. Shared Attachment Replication

- [x] 6.1 Validate and stage at most one supported attachment in a reusable temporary source that is not consumed by the first payment media operation.
- [x] 6.2 Append an independent copy of the attachment to every generated payment's existing single-file `attachments` collection so every payment history can retrieve it independently.
- [x] 6.3 Track and clean staged files, copied files, and media records when any attachment copy or payment operation fails, and roll back every database payment from that submission.
- [x] 6.4 Add tests for attachment-free submission, one attachment copied to every generated payment, independent media records, and full cleanup/rollback on an injected replication failure.

## 7. Verification and Regression Coverage

- [x] 7.1 Verify isolated multi-payment creations end-to-end focusing on accurate balances, status transitions (e.g., PARTIAL to PAID), exact references, attachments, and Indonesian success/error states.
- [x] 7.2 Run component/integration tests ensuring that other tenant users cannot pollute the active multi-payment form via malicious candidate injection.
- [x] 7.3 Conduct a targeted manual review to ensure `Purchase::withArchived()` and DB transactional guarantees behave flawlessly even on mixed LIVE/ARCHIVED statuses.
- [x] 7.4 Review changes holistically across `openspec` tracking artifacts, marking as complete and deploying when all validation criteria are unequivocally satisfied.
