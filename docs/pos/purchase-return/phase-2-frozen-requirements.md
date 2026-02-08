# Phase 2 - Frozen Requirements

## Status
- Status: **Frozen**
- Source: `docs/pos/purchase-return/phase-1-requirements-brainstorm.md` (locked answers)
- This file is the binding contract for implementation and tests.

## 1) Scope

### In-Scope
- Purchase settlement behavior change for **`MODIFY_PURCHASE` only**:
  - Replace auto-delete of `purchase_payments` with payment invalidation.
- Purchase payment lifecycle change:
  - Add explicit invalidation state (`non-counted`) instead of destructive removal by settlement logic.
  - Keep hard delete available, but only for already invalidated payments.
- Source purchase serial visibility change:
  - For serials returned via modify-purchase, keep serial visible in purchase detail/show context.
  - Render returned serial with **red pill marker**.
- Calculation logic alignment:
  - Paid/unpaid/payment_status uses effective payments (active only).
- Migration/backfill:
  - Existing purchase payments become `ACTIVE` by default.

### Out-of-Scope
- No redesign of purchase-return flow structure.
- No behavior change to settlement methods other than `MODIFY_PURCHASE`.
- No report/export redesign (payment/financial report format unchanged).
- No reactivation flow for invalidated payments.
- No reconstruction of historically deleted payments.
- No purchase edit-flow redesign (approved/received purchase edit gate remains as-is).
- No purchase-return-payment lifecycle redesign in this release.

## 2) User Stories / Use Cases (By Role)

### Settlement Approver
- As a settlement approver, when approving `MODIFY_PURCHASE`, I want source purchase payments to be invalidated (not deleted) so settlement effects are reversible in audit terms and history is preserved until explicit deletion.

### Payment Operator (Current `purchasePayments.delete` Permission Holder)
- As a payment operator, I want to invalidate a purchase payment so it is excluded from paid/unpaid totals without losing row history.
- As a payment operator, I can permanently delete only payments that are already invalidated.

### Purchase Viewer
- As a purchase viewer, I want returned serials to remain visible on purchase detail/show with a red pill so I can trace what was returned.

### Auditor / Finance Reviewer
- As an auditor, I want settlement-driven payment changes to be explainable by status transitions rather than silent hard deletes.

## 3) Functional Requirements (Numbered, Testable)

### A. Purchase Return / Settlement (`MODIFY_PURCHASE`)
1. `FR-001` The system SHALL change settlement payment handling only for `MODIFY_PURCHASE`; no behavior change is introduced for other methods in this release.
2. `FR-002` On `MODIFY_PURCHASE` approval, the system SHALL NOT hard-delete `purchase_payments`.
3. `FR-003` On `MODIFY_PURCHASE` approval, the system SHALL invalidate **all active** payments belonging to the affected source purchase.
4. `FR-004` Each invalidated payment SHALL be marked `INVALIDATED` and become non-counted for effective settlement/paid calculations.
5. `FR-005` Invalidation SHALL be final (no reactivation operation in UI/API/service).

### B. Payments & Calculation Logic
6. `FR-006` Effective paid amount for purchase SHALL be computed as `SUM(purchase_payments.amount WHERE status = ACTIVE)`.
7. `FR-007` `purchases.due_amount` SHALL be recalculated as `max(0, total_amount - effective_paid_amount)`.
8. `FR-008` `purchases.payment_status` SHALL be derived from effective amounts using existing status vocabulary (`Unpaid`/`Partial`/`Paid`).
9. `FR-009` Invalidated payments SHALL NOT contribute to any purchase paid/unpaid/status recalculation path.
10. `FR-010` Purchase payment list SHALL display payment status state for each row (`ACTIVE` or `INVALIDATED`).
11. `FR-011` Purchase payment list SHALL expose `Invalidate` and `Delete` actions; `Delete` SHALL only be allowed for invalidated rows.
12. `FR-012` Deleting an `ACTIVE` payment SHALL be rejected.
13. `FR-013` Hard delete of an `INVALIDATED` payment SHALL be allowed for users with existing `purchasePayments.delete` permission.
14. `FR-014` Hard deleting an already-invalidated payment SHALL NOT change effective paid/unpaid totals.

### C. Serial-Number Inventory / Purchase UI Rendering
15. `FR-015` Serials returned through `MODIFY_PURCHASE` SHALL remain visible in source purchase detail/show context.
16. `FR-016` Returned serial rendering in purchase detail/show SHALL use a **red pill marker** (and not be removed from view).
17. `FR-017` Existing serial state transition behavior (`RETURNED` status handling) remains unchanged; this release changes visibility/rendering behavior in purchase view.

### D. Boundaries
18. `FR-018` Purchase-return core workflow steps (create/approve/dispatch/receive) SHALL remain behaviorally unchanged unless directly needed for `FR-001` to `FR-017`.
19. `FR-019` No financial report/export output contract SHALL be changed in this release.
20. `FR-020` Existing previously deleted payments SHALL remain unreconstructed (documented limitation).

## 4) Non-Functional Requirements
1. `NFR-001 Auditability`: Settlement-driven payment state changes must be explainable from persisted payment state and timestamps.
2. `NFR-002 Safety`: No silent destructive payment deletion in `MODIFY_PURCHASE` path.
3. `NFR-003 Determinism`: Re-running totals on same persisted state must produce identical paid/due/status.
4. `NFR-004 Performance`: Added invalidation filters must not introduce full-table scans on normal purchase payment listing and settlement recalculation paths.
5. `NFR-005 Backward Compatibility`: Existing integrations/screens continue to work without report/export schema changes.

## 5) API Contracts (Applicable Internal Web/API Surface)

### A. Existing Settlement Approval Path (Behavior Change)
- Endpoint: `POST /purchase-returns/settlements/item/{itemSettlement}/approve`
- Contract change:
  - For `method = MODIFY_PURCHASE`, source purchase payments are invalidated, not deleted.
  - Response contract remains existing success/error redirect behavior.

### B. New Invalidate Purchase Payment Action
- Endpoint (new): `POST /purchase-payments/{purchasePayment}/invalidate`
- Authorization: same permission scope as current payment delete (`purchasePayments.delete`).
- Preconditions:
  - Payment belongs to active setting scope.
  - Payment is currently `ACTIVE`.
- Effects:
  - Set status to `INVALIDATED`.
  - Set invalidation metadata fields (see Data Model section).
- Failure:
  - `403` unauthorized.
  - `404` not found/out-of-scope.
  - `422` invalid state transition.

### C. Existing Delete Purchase Payment Action (Rule Change)
- Endpoint: `DELETE /purchase-payments/destroy/{purchasePayment}`
- Contract change:
  - Delete is allowed only when payment status is `INVALIDATED`.
  - Reject delete for `ACTIVE` payment.

## 6) Validation & Error Handling Rules
1. `VAL-001` Invalidation request for non-existent payment returns not found.
2. `VAL-002` Invalidation request for already-invalidated payment returns invalid transition.
3. `VAL-003` Delete request for active payment returns invalid transition.
4. `VAL-004` Unauthorized invalidate/delete requests are denied by gate.
5. `VAL-005` Settlement `MODIFY_PURCHASE` with zero active payments still succeeds and recalculates totals deterministically.
6. `VAL-006` If purchase context/setting mismatch occurs, operation must fail safely (no partial mutation).

## 7) Data Model Changes (Fields, Enums, Migrations, Backfill)

### A. `purchase_payments` Table
- Add `status` (string/enum): values `ACTIVE`, `INVALIDATED`; default `ACTIVE`; indexed.
- Add `invalidated_at` (timestamp nullable).
- Add `invalidated_by` (foreign key nullable to users table).
- Add `invalidation_source` (string nullable; e.g. `MODIFY_PURCHASE_SETTLEMENT` or `MANUAL`).
- Add `invalidation_source_id` (unsigned big integer nullable; for settlement item id or null for manual).

### B. Backfill Rules
- `MIG-001` All existing `purchase_payments` rows backfilled to `status = ACTIVE`.
- `MIG-002` Existing rows keep null invalidation metadata.
- `MIG-003` Historically deleted payments are not backfilled/reconstructed.

### C. No Required Schema Changes (This Release)
- No schema change required for `purchase_return_payments`.
- No mandatory schema change required for `product_serial_numbers` to deliver red-pill purchase rendering behavior.

## 8) Security & Permission Considerations
1. `SEC-001` Invalidate purchase payment uses existing `purchasePayments.delete` permission scope.
2. `SEC-002` Hard delete purchase payment also uses existing `purchasePayments.delete` permission scope.
3. `SEC-003` Delete operation is blocked unless payment already invalidated.
4. `SEC-004` Existing setting/tenant scoping checks must apply to invalidate/delete operations.

## 9) Accounting & Inventory Invariants
1. `INV-001` `effective_paid = SUM(amount where status = ACTIVE)`.
2. `INV-002` `due_amount = max(0, total_amount - effective_paid)`.
3. `INV-003` Invalidated payments never affect effective paid/due/payment_status.
4. `INV-004` `MODIFY_PURCHASE` settlement does not auto-delete payment records.
5. `INV-005` Hard delete is explicit-only and allowed only after invalidation.
6. `INV-006` Returned serials from modify-purchase remain visible in purchase detail/show and are marked with red pill.
7. `INV-007` Serial lifecycle semantics (`ACTIVE`, `RETURN_IN_PROCESS`, `RETURNED`, `BROKEN`) remain unchanged in this release.

## 10) Acceptance Criteria Checklist
- [ ] `MODIFY_PURCHASE` no longer hard-deletes source `purchase_payments`.
- [ ] `MODIFY_PURCHASE` invalidates all active source purchase payments.
- [ ] Effective purchase paid/due/status excludes invalidated payments.
- [ ] Purchase payment list shows payment state and supports invalidate action.
- [ ] Purchase payment hard delete is rejected for active payments.
- [ ] Purchase payment hard delete is allowed for invalidated payments with proper permission.
- [ ] Source purchase detail/show displays returned serials with red pill marker.
- [ ] No redesign/regression in purchase-return flow outside defined scope.
- [ ] No report/export format change introduced.
- [ ] Migration backfills existing purchase payments to `ACTIVE`.
- [ ] Regression suite covers settlement math + payment lifecycle + serial rendering behavior.

---
Requirements in this file are frozen and are the baseline for Phase 3 TODO breakdown.
