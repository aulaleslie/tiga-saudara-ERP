## 1. Focused Regression Coverage

- [x] 1.1 Add focused sales-payment feature tests for read-only detail access, note-only updates, immutable submitted fields, edit authorization, active-setting/parent ownership, archived-parent rejection, and invalidated-payment rejection.
- [x] 1.2 Add focused purchase-payment feature tests for the equivalent read-only detail, note-only update, immutability, authorization, ownership, archived-parent, and invalidated-payment behavior.
- [x] 1.3 Extend focused sales and purchase payment DataTable tests to cover the escaped Catatan value, empty-note marker, View action, permission-sensitive note control, direct Delete action, and preserved global read-only mode.
- [x] 1.4 Add focused deletion tests for active and manually invalidated eligible payments, protected credit/automated-lineage payments, last and partial payment balance outcomes, canonical drift repair, and transactional rollback.

## 2. Canonical Balance and Eligibility Logic

- [x] 2.1 Add or consolidate a Purchase parent reconciliation method that derives paid amount, due amount, and normalized payment status from active purchase payments using the established monetary tolerance.
- [x] 2.2 Add explicit reusable deletion-eligibility checks for SalePayment and PurchasePayment that protect credit applications, automated invalidation lineage, and any discovered dependent settlement records.
- [x] 2.3 Update sales and purchase payment deletion handlers to lock the actual parent, recheck eligibility and scope, delete directly, and invoke canonical parent reconciliation in one database transaction.
- [x] 2.4 Remove the normal purchase-history requirement to manually invalidate an active payment before deletion while preserving status APIs and automated invalidation behavior used by correction and return workflows.

## 3. Read-Only Detail and Note-Only Mutation

- [x] 3.1 Harden sales payment detail and update routes/controllers to resolve the actual parent relationship, enforce route-parent and active-setting ownership, expose read-only detail data, and accept only a normalized validated note for maintainable active payments.
- [x] 3.2 Apply the equivalent read-only detail, ownership, archived-state, and note-only update behavior to purchase payment routes/controllers.
- [x] 3.3 Replace the sales payment edit form with a read-only payment detail and an edit-permission-gated note modification control, preserving attachment viewing and clear success/error navigation.
- [x] 3.4 Replace the purchase payment edit form with the matching read-only detail and note modification experience.

## 4. Payment Presentation

- [x] 4.1 Add an escaped, exportable, printable Catatan column with a consistent empty marker to SalePaymentsDataTable and PurchasePaymentsDataTable without adding relationship queries.
- [x] 4.2 Replace normal pencil actions with eye/View actions, show direct deletion only for eligible non-archived payments, remove the normal manual purchase Invalidate action, and preserve global-mode read-only actions.

## 5. Focused Verification

- [x] 5.1 Run only the focused Sale payment controller, balance, invalidation, credit-locking, and DataTable tests touched by this change; fix any regressions.
- [x] 5.2 Run only the focused Purchase payment controller, effective-total, invalidation/delete-policy, authorization, and DataTable tests touched by this change; fix any regressions.
- [x] 5.3 Run Pint only against changed PHP files and inspect the final diff for unintended monetary mutation paths, unrelated changes, or schema/dependency additions.
