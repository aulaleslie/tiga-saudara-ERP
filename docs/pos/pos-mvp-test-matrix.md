# POS MVP Test Matrix (Critical Paths)

Date: 2026-02-26  
Based on:
- `docs/pos/pos-requirements-discovery.md` Sections 4-6
- `docs/pos/pos-hybrid-technical-design.md`
- `docs/pos/pos-mvp-backlog-tests-first.md`

## 1. Purpose

This matrix defines the minimum test scenarios for POS MVP release validation, with emphasis on:

1. hybrid posting integrity (`sales` + `dispatch` + `sale_payments`)
2. multi-location routing and tax-by-source behavior
3. idempotency and concurrency safety
4. session/cash control and supervisor approvals

This matrix is intended to drive automated tests first, then UAT and controlled rollout validation.

## 2. Test Strategy

### 2.1 Test Layers

1. `Unit`  
Pure calculators/resolvers/validators (cash expected totals, allocation resolver, tax snapshot logic).

2. `Feature / Integration`  
HTTP/Livewire/service-level flows hitting DB and real models (preferred for checkout/session behavior).

3. `UAT / Manual`  
Hardware integration, cashier UX speed, training/SOP validation, printer/drawer variability.

### 2.2 Minimum Automated Release Gate

The following categories must be automated before production enablement:

1. Session open/close + variance approval rule
2. Safe drop workflow + threshold behavior
3. Checkout finalization idempotency and rollback
4. Multi-location stock allocation fallback
5. Tax-by-source snapshot persistence
6. Serial validation before payment confirm
7. Hybrid posting cross-reference consistency
8. Full-payment-only rule and non-cash reference validation

## 3. Test Data Matrix Dimensions

Use combinations from these dimensions to build fixtures/factories.

### 3.1 Business / Tenant Context

- `B1` non-PKP business
- `B2` PKP business
- `B3` business with borrowed location configured (owner can be PKP or non-PKP)

### 3.2 Location Topology

- `L1` single owned location, sufficient stock
- `L2` multiple owned locations, fallback required
- `L3` owned + borrowed allowed, borrowed used for fallback
- `L4` insufficient stock across all allowed locations

### 3.3 Product Types

- `P1` standard non-serial
- `P2` serial-tracked
- `P3` bundle (contains standard items)

### 3.4 Payments

- `PM1` cash exact
- `PM2` cash overpay (change)
- `PM3` transfer with reference
- `PM4` QRIS with reference
- `PM5` invalid partial payment (phase 1 should reject)

### 3.5 Session Cash Control

- `S1` threshold not exceeded
- `S2` threshold exceeded, safe drop required
- `S3` session close no variance
- `S4` session close variance above approval threshold

### 3.6 Approval Scenarios

- `A1` valid supervisor PIN and permission
- `A2` invalid PIN
- `A3` valid PIN but missing permission

## 4. Scenario Catalog (Automated Priority)

Legend:

- `Priority P0` = required before enablement
- `Priority P1` = strongly recommended for MVP
- `Type` = Unit / Feature / Integration / UAT

### 4.1 Feature Flags, Terminals, and Session Lifecycle

#### POS-TM-001 (P0) - POS Route Blocked When Feature Disabled

- Type: `Feature`
- Preconditions:
  - Business `B1`
  - POS feature disabled
- Steps:
  1. Authenticate cashier under `B1`.
  2. Access POS sell route.
- Expected:
  - Access denied or redirected.
  - No POS session created.

#### POS-TM-002 (P0) - Session Open Success With Opening Float

- Type: `Feature`
- Preconditions:
  - `B1`, terminal active, feature enabled
  - cashier has POS session permission
- Steps:
  1. Open session with total and denomination breakdown.
- Expected:
  - `pos_sessions` row created in `OPEN` state.
  - `OPEN_FLOAT` cash event created.
  - expected cash equals opening float total.

#### POS-TM-003 (P0) - Prevent Duplicate Active Session Same Cashier/Terminal

- Type: `Feature`
- Preconditions:
  - Existing open session for cashier + terminal
- Steps:
  1. Attempt second session open.
- Expected:
  - Request rejected with conflict/validation.
  - No duplicate active session row.

#### POS-TM-004 (P0) - Session Close No Variance (No Supervisor Approval)

- Type: `Feature`
- Preconditions:
  - Open session with known expected cash
  - `S3`
- Steps:
  1. Submit close with counted cash == expected cash.
- Expected:
  - Session moves to `CLOSED`.
  - close-count event logged.
  - no supervisor approval required.

#### POS-TM-005 (P0) - Session Close Variance Above Threshold Requires Approval

- Type: `Feature`
- Preconditions:
  - Open session with expected cash
  - variance threshold configured
  - `S4`
- Steps:
  1. Cashier submits close with variance above threshold.
  2. Try finalize without approval.
  3. Approve with supervisor PIN (`A1`).
  4. Finalize close.
- Expected:
  - Step 2 blocked.
  - Approval logged.
  - Session closes successfully after approval.

### 4.2 Cash Threshold and Safe Drop Workflow

#### POS-TM-010 (P0) - Threshold Warning Triggered

- Type: `Feature`
- Preconditions:
  - Open session
  - threshold configured low enough
  - cash sale events push expected cash over threshold (`S2`)
- Steps:
  1. Load session monitor / cashier session summary.
- Expected:
  - Threshold breach status flagged for session.
  - Supervisor monitor query returns breached session.

#### POS-TM-011 (P0) - Safe Drop Success With Supervisor PIN

- Type: `Feature`
- Preconditions:
  - Threshold breached session
  - `A1`
- Steps:
  1. Cashier initiates safe drop.
  2. Supervisor approves PIN.
  3. Confirm drop amount.
- Expected:
  - `SAFE_DROP_OUT` cash event created.
  - expected cash decreases by drop amount.
  - approval and audit logs recorded.

#### POS-TM-012 (P0) - Safe Drop Rejected On Invalid Supervisor PIN

- Type: `Feature`
- Preconditions:
  - Threshold breached session
  - `A2`
- Steps:
  1. Cashier initiates safe drop.
  2. Supervisor PIN invalid.
- Expected:
  - No safe-drop event created.
  - Approval rejection logged (or failed attempt logged).
  - expected cash unchanged.

### 4.3 Checkout Validation and Payment Rules

#### POS-TM-020 (P0) - Full Paid Cash Exact (Happy Path)

- Type: `Feature/Integration`
- Preconditions:
  - Active session
  - `L1`, `P1`, `PM1`
- Steps:
  1. Add item to cart.
  2. Confirm payment exact amount.
  3. Finalize checkout with unique idempotency key.
- Expected:
  - POS checkout status `POSTED`.
  - Sale, dispatch/deduction, and sale payment are created.
  - Session cash increases by sale cash amount.
  - Receipt number assigned.

#### POS-TM-021 (P0) - Cash Overpay Computes Change and Posts

- Type: `Feature/Integration`
- Preconditions:
  - Active session
  - `L1`, `P1`, `PM2`
- Steps:
  1. Finalize checkout with cash amount > grand total.
- Expected:
  - Checkout succeeds.
  - `change_total` is correct.
  - `paid_total` and session cash reflect accepted cash policy (net received for expected cash must be defined and consistent).

Note:
- Clarify implementation whether session expected cash uses tendered cash then deducts change, or directly net cash received. Test should assert the chosen rule consistently.

#### POS-TM-022 (P0) - Transfer/QRIS Requires Reference

- Type: `Feature`
- Preconditions:
  - Active session
  - `PM3` or `PM4`
- Steps:
  1. Attempt finalization without reference.
  2. Retry with reference.
- Expected:
  - Step 1 rejected with validation error.
  - Step 2 succeeds.

#### POS-TM-023 (P0) - Partial Payment Rejected In Phase 1

- Type: `Feature`
- Preconditions:
  - Active session
  - `PM5`
- Steps:
  1. Submit amount less than grand total.
- Expected:
  - Validation failure.
  - No posting to `sales` / `dispatch` / `sale_payments`.

### 4.4 Idempotency, Atomicity, and Rollback

#### POS-TM-030 (P0) - Duplicate Payment Confirm With Same Idempotency Key

- Type: `Integration`
- Preconditions:
  - Active session
  - valid checkout payload
- Steps:
  1. Finalize checkout with key `K1`.
  2. Immediately retry same payload with `K1`.
- Expected:
  - Only one sale/payment/deduction set is created.
  - Second response returns same checkout result (or explicit idempotent replay response).

#### POS-TM-031 (P0) - Double Submit Concurrency (In-Progress Conflict)

- Type: `Integration`
- Preconditions:
  - Ability to trigger concurrent finalization on same checkout key/payload
- Steps:
  1. Fire two requests concurrently for same cart and idempotency key.
- Expected:
  - One wins and posts.
  - Second returns conflict or idempotent replay, but no duplicate posting occurs.

#### POS-TM-032 (P0) - Rollback On Mid-Posting Failure

- Type: `Integration`
- Preconditions:
  - Inject failure after sale creation but before final commit (test seam/fake)
- Steps:
  1. Run finalization.
- Expected:
  - No partial `sale`/`dispatch`/`sale_payment` remains committed.
  - Checkout marked failed (or no posted state).
  - Failure logged for diagnostics.

### 4.5 Stock Routing and Allocation (Multi-Location)

#### POS-TM-040 (P0) - Single Location Allocation (No Fallback)

- Type: `Unit + Feature`
- Preconditions:
  - `L1`
  - sufficient stock in preferred location
- Steps:
  1. Resolve allocations.
  2. Finalize checkout.
- Expected:
  - Allocation uses one location only.
  - Posted deduction attribution matches preferred location.

#### POS-TM-041 (P0) - Fallback To Next Configured Location

- Type: `Unit + Integration`
- Preconditions:
  - `L2`
  - preferred location insufficient
  - second location sufficient
- Steps:
  1. Resolve allocations.
  2. Finalize checkout.
- Expected:
  - Allocation split or fallback uses next configured location.
  - Posted dispatch detail(s) preserve source-location attribution.

#### POS-TM-042 (P0) - Borrowed Location Used When Configured

- Type: `Integration`
- Preconditions:
  - `L3`
  - borrowed location configured and available
- Steps:
  1. Finalize checkout requiring borrowed location stock.
- Expected:
  - Allocation includes borrowed location.
  - Source owner business attribution preserved.

#### POS-TM-043 (P0) - Insufficient Stock Across Allowed Locations Blocks Checkout

- Type: `Feature`
- Preconditions:
  - `L4`
- Steps:
  1. Attempt finalization.
- Expected:
  - Checkout blocked with stock message.
  - No ERP posting created.

#### POS-TM-044 (P1) - Deterministic Allocation Order

- Type: `Unit`
- Preconditions:
  - multiple candidate locations with same stock patterns
- Steps:
  1. Run resolver repeatedly with same inputs.
- Expected:
  - Same allocation order/result each time.

### 4.6 Tax-by-Source Snapshot and Mixed Tax Scenarios

#### POS-TM-050 (P0) - Tax Policy Follows Source Location Owner (Single Source)

- Type: `Integration`
- Preconditions:
  - source location owner business has known PKP/non-PKP setting
- Steps:
  1. Finalize checkout from that source.
- Expected:
  - Posted tax snapshot and line amounts match source owner tax policy.

#### POS-TM-051 (P0) - Mixed Tax Outcomes In One Checkout (Split Sources)

- Type: `Integration`
- Preconditions:
  - Allocation split across sources with different tax policies
- Steps:
  1. Finalize checkout.
  2. Inspect POS allocation snapshots and posted line tax outcomes.
- Expected:
  - Each line/allocation portion preserves correct tax snapshot.
  - Totals reconcile to grand total and posted payment.

#### POS-TM-052 (P0) - Historical Stability After Tax Config Change

- Type: `Integration`
- Preconditions:
  - Completed POS checkout with stored tax snapshots
- Steps:
  1. Change source business tax configuration.
  2. Reload historical transaction/report.
- Expected:
  - Historical transaction values do not change.

### 4.7 Serial-Tracked Product Validation

#### POS-TM-060 (P0) - Serial Required Before Payment Confirm

- Type: `Feature`
- Preconditions:
  - Cart contains `P2`
- Steps:
  1. Attempt payment without serial assignment.
- Expected:
  - Validation failure before finalization.

#### POS-TM-061 (P0) - Invalid/Used Serial Rejected

- Type: `Feature/Integration`
- Preconditions:
  - Serial already used or unavailable
- Steps:
  1. Enter serial.
  2. Attempt confirm.
- Expected:
  - Serial validation error shown.
  - Checkout not posted.

#### POS-TM-062 (P0) - Valid Serial Across Multi-Location Allocation

- Type: `Integration`
- Preconditions:
  - serial stock distributed across multiple allowed locations
- Steps:
  1. Assign valid serial(s).
  2. Finalize checkout.
- Expected:
  - Serial assignments map to correct source locations.
  - Posted deduction preserves serial attribution.

### 4.8 Pricing, Discount Overrides, and Supervisor PIN

#### POS-TM-070 (P0) - Price Override Requires Supervisor Approval

- Type: `Feature`
- Preconditions:
  - cashier attempts price override beyond allowed policy
- Steps:
  1. Edit price without approval.
  2. Retry with supervisor PIN (`A1`).
- Expected:
  - Step 1 blocked.
  - Step 2 allowed.
  - Approval log created.

#### POS-TM-071 (P0) - Discount Override Invalid PIN Blocks Action

- Type: `Feature`
- Preconditions:
  - `A2`
- Steps:
  1. Attempt discount override with invalid supervisor PIN.
- Expected:
  - Override rejected.
  - Approval failure audit recorded.

#### POS-TM-072 (P1) - Valid PIN But Missing Permission

- Type: `Feature`
- Preconditions:
  - `A3`
- Steps:
  1. Attempt protected action.
- Expected:
  - Action rejected despite valid PIN.

### 4.9 Hybrid Posting Cross-Reference and Reconciliation

#### POS-TM-080 (P0) - Cross-Reference IDs Stored For Posted Checkout

- Type: `Integration`
- Preconditions:
  - Successful checkout
- Steps:
  1. Inspect `pos_checkouts`.
- Expected:
  - `sale_id`, `sale_payment_id`, and `dispatch_ids` (or normalized links) are stored.
  - `receipt_number` stored.

#### POS-TM-081 (P0) - Session Cash Reconciles With Cash Sales and Safe Drops

- Type: `Integration`
- Preconditions:
  - Open session with opening float
  - cash sales + safe drop(s) posted
- Steps:
  1. Query session summary.
  2. Compare to event ledger.
- Expected:
  - expected cash = opening + cash sales - safe drops +/- adjustments.

#### POS-TM-082 (P1) - Reconciliation Report Flags Mismatch

- Type: `Integration`
- Preconditions:
  - Seed mismatch fixture (or diagnostic simulation)
- Steps:
  1. Open reconciliation view/report.
- Expected:
  - mismatch flagged with traceable IDs.

### 4.10 Receipt Printing and Hardware Adapters

#### POS-TM-090 (P1) - Receipt Number Follows Business Configuration

- Type: `Feature/Integration`
- Preconditions:
  - business receipt numbering config defined
- Steps:
  1. Complete checkout.
- Expected:
  - receipt number format matches business config.

#### POS-TM-091 (P1) - Printer Failure Does Not Roll Back Posting

- Type: `Integration`
- Preconditions:
  - Simulated print adapter failure after successful posting
- Steps:
  1. Complete checkout and trigger print.
- Expected:
  - ERP posting remains committed.
  - print log records failure.
  - cashier can retry/reprint.

#### POS-TM-092 (P1) - Cash Drawer Hook Failure Is Non-Fatal

- Type: `Integration/UAT`
- Preconditions:
  - drawer enabled terminal
  - adapter failure simulated
- Steps:
  1. Trigger drawer-open event (session open or cash sale).
- Expected:
  - POS operation continues.
  - drawer failure logged.

## 5. UAT / Manual Scenario Set (Non-Automation-First)

These are still required before broad enablement even if the core release gate is automated.

### 5.1 Cashier Workflow Usability

- `POS-UAT-001` Keyboard-heavy cashier completes 10 common transactions without UI dead-ends.
- `POS-UAT-002` Touchscreen cashier can complete sell-pay-print flow without precision clicks.
- `POS-UAT-003` Barcode scanning cadence does not lose scans under normal counter speed.

### 5.2 Hardware and Ops

- `POS-UAT-010` Network thermal printer works on each target store network profile.
- `POS-UAT-011` Reprint flow works after print failure recovery.
- `POS-UAT-012` Drawer behavior matches terminal policy per store (where hardware exists).

### 5.3 Parallel Run and Fallback SOP

- `POS-UAT-020` Team can switch to current sales/manual invoice fallback during simulated POS outage.
- `POS-UAT-021` Parallel-run SOP prevents duplicate transaction entry.
- `POS-UAT-022` Supervisor can reconcile session and sign off variance workflow during shift close.

## 6. Automation Mapping (Suggested Test File Groups)

These are suggested groupings, not fixed file names.

### 6.1 Session / Cash Control

- `POSSessionLifecycleTest`
- `POSSafeDropWorkflowTest`
- `POSSessionCloseVarianceApprovalTest`
- `POSExpectedCashCalculatorTest`

### 6.2 Checkout / Posting

- `POSCheckoutFinalizeIdempotencyTest`
- `POSCheckoutRollbackAtomicityTest`
- `POSPaymentValidationRulesTest`
- `POSHybridPostingCrossReferenceTest`

### 6.3 Inventory / Tax / Serial

- `POSStockAllocationResolverTest`
- `POSMultiLocationCheckoutPostingTest`
- `POSTaxBySourceSnapshotTest`
- `POSSerialValidationBeforePaymentTest`

### 6.4 Approvals / Audit

- `POSSupervisorPinApprovalTest`
- `POSOverrideAuditLoggingTest`

### 6.5 Printing / Hardware (Mostly Integration Fakes)

- `POSReceiptPrintingFailureHandlingTest`
- `POSCashDrawerHookFailureHandlingTest`

## 7. Execution Order for Test Implementation

Recommended order (to match build sequence and maximize early risk reduction):

1. Session lifecycle tests (`POS-TM-001` to `POS-TM-005`)
2. Payment rule + checkout idempotency tests (`POS-TM-020` to `POS-TM-032`)
3. Stock allocation fallback tests (`POS-TM-040` to `POS-TM-044`)
4. Tax-by-source snapshot tests (`POS-TM-050` to `POS-TM-052`)
5. Serial validation tests (`POS-TM-060` to `POS-TM-062`)
6. Supervisor PIN/override tests (`POS-TM-070` to `POS-TM-072`)
7. Reconciliation and cross-reference tests (`POS-TM-080` to `POS-TM-082`)
8. Printer/drawer non-fatal behavior tests (`POS-TM-090` to `POS-TM-092`)

## 8. Release Sign-Off Checklist (Test Matrix Driven)

Mark complete before production enablement:

1. All `P0` automated scenarios passing
2. No duplicate posting observed in idempotency/concurrency tests
3. Multi-location + tax-source scenarios validated for serial and non-serial items
4. Session close variance approval path validated
5. Safe drop threshold monitoring and approval path validated
6. Printer failure and reprint recovery validated on representative hardware/store setup
7. UAT fallback/parallel-run SOP executed successfully
