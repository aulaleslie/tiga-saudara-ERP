# Phase 3 - TODO Breakdown (Tests-First, Safety-First)

## Execution Mode (AI-Agent Friendly)
- Follow TODOs in order.
- For each TODO: write tests first, make tests fail for the new rule, then implement, then make tests pass.
- Do not skip Definition of Done checks.
- Do not broaden scope beyond Phase 2 frozen requirements.

## Milestone 1 - Data Model

### TODO 1 — Add Purchase Payment Invalidation Schema
**Goal:**  
Add non-destructive invalidation fields to `purchase_payments` with safe defaults and indexes.

**Related requirements:**  
`FR-004`, `FR-010`, `FR-013`, `FR-014`, `MIG-001`, `MIG-002`, `INV-003`, `INV-005`

**Impacted paths:**  
`Modules/Purchase/Database/Migrations/*`  
`Modules/Purchase/Entities/PurchasePayment.php`

**Test cases (Given / When / Then):**
- Given existing `purchase_payments` rows, when migration runs, then all rows have `status = ACTIVE`.
- Given migrated schema, when inserting new payment, then status defaults to `ACTIVE`.
- Given invalidated payment, when queried by active scope, then payment is excluded.

**Unit test plan:**
- Test file name: `Modules/Purchase/Tests/Feature/PurchasePaymentInvalidationMigrationTest.php`
- What to mock: none (DB-backed migration test).
- Assertions:
  - columns exist: `status`, `invalidated_at`, `invalidated_by`, `invalidation_source`, `invalidation_source_id`
  - default status is `ACTIVE`
  - index on `status` exists
- Edge cases & failure modes:
  - legacy rows with nulls
  - migration rollback/forward cycle

**Integration / E2E tests (if applicable):**
- Fresh migrate + seed smoke run to ensure purchase payment CRUD still works.

**Implementation outline (NO CODE):**
- Create forward migration with new columns and index.
- Backfill existing rows to `ACTIVE`.
- Add model constants/casts/scope placeholders for later TODOs.

**Definition of Done:**
- All tests pass
- No destructive side effects
- Accounting totals verifiably correct
- UI accurately reflects domain state

### TODO 2 — Add Model-Level Lifecycle Contracts for Payment Status
**Goal:**  
Define canonical payment status behavior in model/query level (`ACTIVE`, `INVALIDATED`) to prevent ad-hoc filtering bugs.

**Related requirements:**  
`FR-006`, `FR-009`, `FR-010`, `INV-001`, `INV-003`

**Impacted paths:**  
`Modules/Purchase/Entities/PurchasePayment.php`  
`Modules/Purchase/DataTables/PurchasePaymentsDataTable.php`

**Test cases (Given / When / Then):**
- Given mixed active/invalidated payments, when active scope is used, then only active rows are returned.
- Given invalidated payment, when DataTable query runs, then row remains visible with status field available.

**Unit test plan:**
- Test file name: `Modules/Purchase/Tests/Unit/PurchasePaymentStatusScopeTest.php`
- What to mock: none.
- Assertions:
  - status constants match frozen requirements
  - active/effective scopes include/exclude correctly
  - status cast/normalization is stable
- Edge cases & failure modes:
  - lowercase/legacy status text
  - null status during rollout window

**Integration / E2E tests (if applicable):**
- Payment list endpoint returns both statuses and supports filtering logic.

**Implementation outline (NO CODE):**
- Add status constants and helper scopes.
- Ensure DataTable query can read status without hiding invalidated rows.
- Keep backward compatibility for existing screens.

**Definition of Done:**
- All tests pass
- No destructive side effects
- Accounting totals verifiably correct
- UI accurately reflects domain state

## Milestone 2 - Calculation Logic

### TODO 3 — Replace `MODIFY_PURCHASE` Payment Deletion with Invalidate-All
**Goal:**  
In settlement approval path for `MODIFY_PURCHASE`, invalidate all active source purchase payments instead of deleting.

**Related requirements:**  
`FR-001`, `FR-002`, `FR-003`, `FR-004`, `INV-004`

**Impacted paths:**  
`Modules/PurchasesReturn/Http/Controllers/PurchasesReturnSettlementController.php`  
`Modules/Purchase/Entities/PurchasePayment.php`

**Test cases (Given / When / Then):**
- Given source purchase has active payments, when `MODIFY_PURCHASE` settlement is approved, then no payment row is deleted.
- Given same case, when settlement completes, then all previously active source payments become `INVALIDATED`.
- Given no active source payments, when settlement is approved, then operation succeeds without errors.

**Unit test plan:**
- Test file name: `Modules/PurchasesReturn/Tests/Feature/ModifyPurchaseInvalidatesPaymentsTest.php`
- What to mock: none; use real DB transactions in feature test.
- Assertions:
  - row count unchanged in `purchase_payments`
  - status transitions to `INVALIDATED`
  - invalidation metadata populated
- Edge cases & failure modes:
  - partially paid purchase
  - fully paid purchase
  - multiple payments with mixed existing statuses

**Integration / E2E tests (if applicable):**
- Settlement UI approve flow confirms status transition and no hard delete.

**Implementation outline (NO CODE):**
- Replace delete branch in `MODIFY_PURCHASE` flow with invalidate-all active branch.
- Set metadata source to settlement context.
- Keep non-`MODIFY_PURCHASE` methods unchanged in this release.

**Definition of Done:**
- All tests pass
- No destructive side effects
- Accounting totals verifiably correct
- UI accurately reflects domain state

### TODO 4 — Recompute Purchase Financial Header from Active Payments Only
**Goal:**  
Ensure `paid_amount`, `due_amount`, and `payment_status` are derived from active payments only in all touched purchase recalculation paths.

**Related requirements:**  
`FR-006`, `FR-007`, `FR-008`, `FR-009`, `INV-001`, `INV-002`, `INV-003`

**Impacted paths:**  
`Modules/PurchasesReturn/Http/Controllers/PurchasesReturnSettlementController.php`  
`Modules/Purchase/Http/Controllers/PurchasePaymentsController.php`

**Test cases (Given / When / Then):**
- Given active and invalidated payments on a purchase, when totals are recalculated, then only active payments count.
- Given all payments invalidated, when recalculated, then `paid_amount = 0`, `due_amount = total_amount`, `payment_status = Unpaid`.
- Given active payments fully cover total, when recalculated, then `payment_status = Paid`.

**Unit test plan:**
- Test file name: `Modules/Purchase/Tests/Feature/PurchaseEffectivePaymentTotalsTest.php`
- What to mock: none.
- Assertions:
  - effective paid sum equals active-only sum
  - due and status follow invariant formulas
  - invalidated delete does not alter effective totals
- Edge cases & failure modes:
  - floating precision at `0.01` thresholds
  - total reduction after modify purchase

**Integration / E2E tests (if applicable):**
- End-to-end: settle modify purchase -> visit purchase detail -> verify totals/status.

**Implementation outline (NO CODE):**
- Centralize active-only sum logic (service/helper).
- Replace raw paid value assumptions with effective calculation where needed.
- Keep existing status vocabulary and UI labels.

**Definition of Done:**
- All tests pass
- No destructive side effects
- Accounting totals verifiably correct
- UI accurately reflects domain state

### TODO 5 — Enforce Delete Preconditions and Invalidate Endpoint Rules
**Goal:**  
Allow hard delete only for invalidated payments and add explicit invalidate action.

**Related requirements:**  
`FR-011`, `FR-012`, `FR-013`, `FR-014`, `VAL-002`, `VAL-003`, `SEC-001`, `SEC-003`

**Impacted paths:**  
`Modules/Purchase/Routes/web.php`  
`Modules/Purchase/Http/Controllers/PurchasePaymentsController.php`

**Test cases (Given / When / Then):**
- Given active payment, when delete endpoint is called, then request is rejected.
- Given invalidated payment, when delete endpoint is called by authorized user, then row is deleted.
- Given active payment, when invalidate endpoint is called, then status changes to `INVALIDATED`.
- Given invalidated payment, when invalidate endpoint is called again, then request is rejected.

**Unit test plan:**
- Test file name: `Modules/Purchase/Tests/Feature/PurchasePaymentInvalidateDeletePolicyTest.php`
- What to mock: none.
- Assertions:
  - HTTP status and redirect/error messages for each state transition
  - permission gate behavior unchanged
  - totals recomputation uses active-only payments
- Edge cases & failure modes:
  - cross-setting payment access attempt
  - invalid payment id

**Integration / E2E tests (if applicable):**
- Payment list action flow: Invalidate -> Delete.

**Implementation outline (NO CODE):**
- Add invalidate route/action.
- Add delete precondition checks.
- Ensure setting scoping and gate checks run for both actions.

**Definition of Done:**
- All tests pass
- No destructive side effects
- Accounting totals verifiably correct
- UI accurately reflects domain state

## Milestone 3 - UI Behavior

### TODO 6 — Add Payment Status Badge and Action Gating in Purchase Payment List
**Goal:**  
Display status per payment row and enforce UI action gating (`Delete` only after invalidation).

**Related requirements:**  
`FR-010`, `FR-011`, `FR-012`, `FR-013`, `SEC-002`, `SEC-003`

**Impacted paths:**  
`Modules/Purchase/DataTables/PurchasePaymentsDataTable.php`  
`Modules/Purchase/Resources/views/payments/partials/actions.blade.php`  
`Modules/Purchase/Resources/views/payments/index.blade.php`

**Test cases (Given / When / Then):**
- Given active payment row, when payment list renders, then status shows `ACTIVE` and delete action is disabled/hidden per rule.
- Given invalidated payment row, when list renders, then status shows `INVALIDATED` and delete action is enabled.
- Given user without delete permission, when list renders, then invalidate/delete actions are hidden.

**Unit test plan:**
- Test file name: `Modules/Purchase/Tests/Feature/PurchasePaymentListStatusActionsTest.php`
- What to mock: DataTables request payload as needed.
- Assertions:
  - status column output is correct
  - action partial respects status + permission
  - no stale `salePayments.*` gate mismatch in purchase payment actions
- Edge cases & failure modes:
  - null/legacy status rows
  - archived purchase context

**Integration / E2E tests (if applicable):**
- Browser scenario: verify row actions change after invalidation.

**Implementation outline (NO CODE):**
- Add status presentation in DataTable output.
- Update action partial to use purchase payment permissions and state gating.
- Keep existing navigation/breadcrumb structure.

**Definition of Done:**
- All tests pass
- No destructive side effects
- Accounting totals verifiably correct
- UI accurately reflects domain state

### TODO 7 — Render Returned Serials as Red Pill in Purchase Detail/Show
**Goal:**  
Ensure returned serials remain visible in purchase detail/show and are marked as red pill.

**Related requirements:**  
`FR-015`, `FR-016`, `FR-017`, `INV-006`, `INV-007`

**Impacted paths:**  
`Modules/Purchase/Http/Controllers/PurchaseController.php`  
`Modules/Purchase/Resources/views/show.blade.php`  
`Modules/Purchase/Resources/views/receivings/receiving-details.blade.php`

**Test cases (Given / When / Then):**
- Given a serial returned by `MODIFY_PURCHASE`, when purchase detail/show is opened, then serial is visible and rendered with red pill marker.
- Given active serial, when rendered on same screen, then it is not shown as red returned pill.
- Given purchase with mixed serial states, when rendered, then each serial state styling matches domain truth.

**Unit test plan:**
- Test file name: `Modules/Purchase/Tests/Feature/PurchaseShowReturnedSerialVisibilityTest.php`
- What to mock: none.
- Assertions:
  - returned serial id/number appears in response
  - returned serial has red-pill class/marker
  - non-returned serial does not use returned marker
- Edge cases & failure modes:
  - serial has `received_note_detail_id = null` after return
  - multiple returns over same purchase

**Integration / E2E tests (if applicable):**
- Settlement approve (`MODIFY_PURCHASE`) then open purchase show page and inspect serial badge color/state.

**Implementation outline (NO CODE):**
- Adjust data source in purchase show flow to include returned serial records relevant to purchase.
- Add red-pill UI rendering rule for returned serial state.
- Keep existing purchase edit gate behavior unchanged.

**Definition of Done:**
- All tests pass
- No destructive side effects
- Accounting totals verifiably correct
- UI accurately reflects domain state

## Milestone 4 - Tests

### TODO 8 — Add High-Confidence Regression Test Matrix (Feature + Unit)
**Goal:**  
Create a focused regression suite that protects settlement math, payment lifecycle, and serial visibility.

**Related requirements:**  
`FR-001` to `FR-020`, `NFR-001` to `NFR-005`, `INV-001` to `INV-007`

**Impacted paths:**  
`Modules/PurchasesReturn/Tests/Feature/*`  
`Modules/Purchase/Tests/Feature/*`  
`Modules/Purchase/Tests/Unit/*`

**Test cases (Given / When / Then):**
- Given modify-purchase settlement, when approved, then payments are invalidated not deleted.
- Given invalidated payments, when totals are recalculated, then paid/due/status use active-only sums.
- Given returned serials, when purchase show renders, then returned serials remain visible with red pill.
- Given delete attempt on active payment, when endpoint called, then operation fails.

**Unit test plan:**
- Test file name: `Modules/Purchase/Tests/Unit/PurchasePaymentEffectiveTotalsMatrixTest.php`
- What to mock: none.
- Assertions:
  - invariant formulas always hold
  - state transitions are deterministic
  - no side-effects on unrelated settlement methods
- Edge cases & failure modes:
  - zero payments
  - mixed statuses
  - decimal rounding edges

**Integration / E2E tests (if applicable):**
- One full workflow scenario from settlement approval to purchase show/payment list verification.

**Implementation outline (NO CODE):**
- Add feature tests first for each frozen requirement cluster.
- Add supporting unit tests for deterministic math and state transitions.
- Fail-fast if any assumption differs from frozen requirements.

**Definition of Done:**
- All tests pass
- No destructive side effects
- Accounting totals verifiably correct
- UI accurately reflects domain state

### TODO 9 — Add Negative Path and Authorization Tests
**Goal:**  
Prove unsafe operations are blocked (delete active, double invalidate, unauthorized transitions).

**Related requirements:**  
`VAL-001` to `VAL-006`, `SEC-001` to `SEC-004`

**Impacted paths:**  
`Modules/Purchase/Tests/Feature/PurchasePaymentInvalidateDeletePolicyTest.php`  
`Modules/PurchasesReturn/Tests/Feature/ModifyPurchaseInvalidatesPaymentsTest.php`

**Test cases (Given / When / Then):**
- Given unauthorized user, when invalidate/delete is attempted, then request is denied.
- Given invalid state transition, when same invalidation is repeated, then request is rejected.
- Given cross-setting resource, when invalidate/delete is attempted, then request is blocked.

**Unit test plan:**
- Test file name: `Modules/Purchase/Tests/Feature/PurchasePaymentInvalidationAuthTest.php`
- What to mock: permission seeding only as needed.
- Assertions:
  - gate checks are enforced
  - state-transition guards are enforced
  - no mutation on failure
- Edge cases & failure modes:
  - stale session setting id
  - not-found payment id

**Integration / E2E tests (if applicable):**
- Role-based UI smoke: action buttons visible/hidden by permission.

**Implementation outline (NO CODE):**
- Add dedicated negative-path tests before controller changes.
- Verify DB remains unchanged after rejected requests.

**Definition of Done:**
- All tests pass
- No destructive side effects
- Accounting totals verifiably correct
- UI accurately reflects domain state

## Milestone 5 - Migration & Regression Safety

### TODO 10 — Safe Rollout, Backfill Verification, and Baseline Stability
**Goal:**  
Ship with reversible migration safety checks and verify existing test baseline remains green.

**Related requirements:**  
`MIG-001`, `MIG-002`, `MIG-003`, `NFR-005`, `FR-020`

**Impacted paths:**  
`Modules/Purchase/Database/Migrations/*`  
`docs/pos/purchase-return/phase-2-frozen-requirements.md`  
`docs/pos/purchase-return/phase-3-todo-breakdown.md`

**Test cases (Given / When / Then):**
- Given pre-change database, when migration is applied, then all existing `purchase_payments` are `ACTIVE`.
- Given migration applied, when full test suite runs, then no unrelated existing tests regress.
- Given release notes/backward-compat note, when reviewed, then limitation on historically deleted payments is explicit.

**Unit test plan:**
- Test file name: `Modules/Purchase/Tests/Feature/PurchasePaymentBackfillSafetyTest.php`
- What to mock: none.
- Assertions:
  - backfill count equals total pre-existing rows
  - no null status left after migration
  - rollback/forward migration integrity
- Edge cases & failure modes:
  - large table migration performance
  - null/invalid legacy payment method linkage rows

**Integration / E2E tests (if applicable):**
- Run `php artisan test` as pre-merge gate and store result summary in PR notes.

**Implementation outline (NO CODE):**
- Run migration in staging-like dataset clone.
- Verify backfill counts and spot-check financial aggregates.
- Run full automated tests and block merge on failures.

**Definition of Done:**
- All tests pass
- No destructive side effects
- Accounting totals verifiably correct
- UI accurately reflects domain state

---
This TODO plan is frozen against Phase 2 and is intentionally tests-first.
