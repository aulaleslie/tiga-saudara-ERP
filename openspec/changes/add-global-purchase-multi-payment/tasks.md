## 1. Permissions and Route Boundaries

- [ ] 1.1 Add `purchasePayments.global.access` to the canonical purchase-payment permission catalog and permission synchronization/migration path without changing existing role assignments implicitly.
- [ ] 1.2 Add named global purchase-payment list, read-only detail/payment-history, create-form, and submit routes before the existing parameterized purchase resource routes, enforcing global access on reads and both global access plus `purchasePayments.create` on writes.
- [ ] 1.3 Add authorization tests proving the menu and all global routes are inaccessible without global access, read routes work without create permission, and payment creation remains forbidden without `purchasePayments.create`.
- [ ] 1.4 Add regression coverage proving the normal purchase detail and existing single-purchase payment routes continue enforcing the active `setting_id` guard.

## 2. Operational Global Payment List

- [ ] 2.1 Extend or wrap the operational purchase index/table with an explicit global-payment mode that never falls back to `session('setting_id')` and does not affect receiving or other embedded purchase-table contexts.
- [ ] 2.2 Implement the global list query across all settings using exact `RECEIVED`, non-archived, positive live outstanding-balance eligibility and the established active purchase-payment amount scaling/semantics.
- [ ] 2.3 Reuse the `Semua Pembelian` columns, search, sorting, pagination, status display, payment display, and layout while removing create/import controls and introducing a payment-only row action presentation.
- [ ] 2.4 Add `Pembayaran Pembelian Global` beside `Semua Pembelian` under the operational `Pembelian` menu, gated by `purchasePayments.global.access` and kept separate from the existing global purchase report under `Laporan`.
- [ ] 2.5 Add Livewire/feature coverage for cross-setting visibility, active-setting independence, exact-status and outstanding-balance filtering, archived exclusion, layout behavior, and absence of create/update/delete/approval/receiving/duplicate/archive actions.

## 3. Global Read-Only Purchase Detail

- [ ] 3.1 Extract or share the current purchase-detail data-loading logic so a dedicated authorized global controller can load purchase, supplier, receiving/serial context, and payment history without calling the normal setting guard.
- [ ] 3.2 Render the existing purchase detail through an explicit global read-only payment context that hides every non-payment mutation and uses dedicated global URLs for payment history and payment creation.
- [ ] 3.3 Implement the global payment-history data endpoint/query without active-setting scope while preserving existing payment status, attachment, and invalidation presentation and exposing no unauthorized mutation.
- [ ] 3.4 Update global list transaction/detail links to use the dedicated global detail route and direct eligible create-payment actions to the supplier multi-payment form.
- [ ] 3.5 Add feature tests proving an authorized user can inspect a purchase and its payments from another setting, cannot access unrelated mutations from that context, and cannot use the global route to view an ineligible/unauthorized resource outside the defined workspace.

## 4. Supplier Allocation Form and Candidate Query

- [ ] 4.1 Create a server-side candidate query/service that validates the starting purchase and loads purchases with the exact same `supplier_id`, exact `RECEIVED` status, non-archived state, and positive live outstanding balance without `setting_id` scope.
- [ ] 4.2 Build the Bootstrap/CoreUI multi-payment page following the sample hierarchy with read-only supplier, date, reference, payment method, invoice allocation table, memo, one attachment, running subtotal/total, cancel, and save controls.
- [ ] 4.3 Render transaction number, description, due date, total, live outstanding balance, and amount per invoice; initialize the starting invoice to its full balance and every other invoice to zero.
- [ ] 4.4 Omit unsupported tags, withholding, separate payment due date, separate persisted account selection, and multiple-attachment controls while optionally displaying the selected payment method's chart-of-account as read-only context.
- [ ] 4.5 Add component/feature tests for sample-inspired supported fields, omitted unsupported fields, exact-supplier cross-setting candidates, starting/default amounts, totals, and rejection of an ineligible starting purchase.

## 5. Atomic Multi-Purchase Payment Orchestration

- [ ] 5.1 Add a dedicated multi-purchase payment request/validator with Indonesian messages for shared fields, normalized amounts, at least one positive allocation, supported single attachment, and tamper-resistant candidate identifiers.
- [ ] 5.2 Implement a transactional orchestration service that locks submitted purchases in stable order, reloads active payments, revalidates supplier/status/archive/live-balance eligibility, and rejects the complete submission when any positive allocation is invalid or stale.
- [ ] 5.3 Create one active existing `PurchasePayment` per positive allocation using the shared date, reference, payment method, and memo while preserving the current amount mutator/accessor storage convention and ignoring zero allocations.
- [ ] 5.4 Recalculate each affected purchase's effective paid amount, due amount, and payment status from active payments within the same database transaction.
- [ ] 5.5 Protect the entire allocation against duplicate submission using the application's idempotency mechanism or an equivalent one-time submission token.
- [ ] 5.6 Redirect successful submissions to an appropriate global payment/detail destination with an Indonesian success message and preserve validated form input/errors on failure.

## 6. Shared Attachment Replication

- [ ] 6.1 Validate and stage at most one supported attachment in a reusable temporary source that is not consumed by the first payment media operation.
- [ ] 6.2 Append an independent copy of the attachment to every generated payment's existing single-file `attachments` collection so every payment history can retrieve it independently.
- [ ] 6.3 Track and clean staged files, copied files, and media records when any attachment copy or payment operation fails, and roll back every database payment from that submission.
- [ ] 6.4 Add tests for attachment-free submission, one attachment copied to every generated payment, independent media records, and full cleanup/rollback on an injected replication failure.

## 7. Verification and Regression Coverage

- [ ] 7.1 Add focused tests for multi-invoice success, partial allocations, zero-row omission, overpayment, negative amounts, supplier tampering, non-`RECEIVED` status, archived purchases, stale balances, and all-or-nothing database rollback.
- [ ] 7.2 Add a concurrency-focused service test or equivalent deterministic lock/revalidation test proving a balance change before commit cannot cause overpayment or partial settlement.
- [ ] 7.3 Verify existing purchase payment history, invalidation, financial report aggregation, attachment display, and setting-scoped single-payment behavior continue recognizing the generated ordinary `PurchasePayment` rows.
- [ ] 7.4 Run the focused purchase/global-payment test filters, then run `composer test:fresh-sqlite` for migration and broader regression confidence; document any environment-only limitations.
