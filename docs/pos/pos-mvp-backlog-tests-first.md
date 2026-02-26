# POS MVP Backlog (Tests-First)

Date: 2026-02-26  
Based on: `docs/pos/pos-requirements-discovery.md` (Sections 4-6 finalized baseline)  
Scope target: POS MVP (`Immediate checkout + hybrid posting + session/cash control`)

## Execution Policy

1. Every backlog item starts with automated tests (feature/integration where behavior crosses DB boundaries).
2. Do not implement production code before the target test cases for that item exist.
3. Run targeted tests after each item, then rerun the POS critical-path suite before closing a milestone.
4. Prefer incremental feature flags so incomplete POS work does not affect current sales flow.

## Delivery Strategy

### Sequencing Principles

1. Build control-plane first (`feature flags`, `terminal config`, `session`), then cashier checkout.
2. Implement posting reliability (`idempotency`, atomic transaction boundaries) before UI polish.
3. Lock down multi-location/tax-source behavior with tests before broad rollout.
4. Treat printer/drawer support as adapter work behind feature toggles.

### Definition of MVP Done (Program-Level)

1. Cashier can open session, sell, receive payment, print receipt, safe-drop cash, and close session.
2. Hybrid posting writes to existing `sales` / `dispatch` / `sale_payments` consistently.
3. Multi-location routing + tax-by-source behavior passes the agreed test matrix.
4. Supervisor PIN approvals and audit logs are enforced for sensitive actions.
5. Release validation checklist in `docs/pos/pos-requirements-discovery.md` Section 6.8 passes.

## Milestone 0 - Foundations and Safety Rails

### TODO 0.1 - POS Feature Flag and Business Enablement Controls

**Goal:**  
Introduce business-scoped POS enablement so rollout remains controllable even though the target scope is all businesses.

**Related requirements:**  
`NFR-4`, `6.8 Rollback Plan`, `5.4 Rollout Strategy`

**Expected touched areas:**  
- new POS settings configuration (business-scoped)  
- admin/business settings UI  
- route/middleware gating for POS screens

**Test cases (Given / When / Then):**
- Given POS feature is disabled for a business, when a cashier accesses POS routes, then access is denied or redirected.
- Given POS feature is enabled for a business, when a cashier with permission accesses POS routes, then POS shell loads.
- Given one business enabled and another disabled, when switching `setting_id`, then behavior changes correctly per business.

**Acceptance criteria:**
1. POS routes are feature-gated by business setting.
2. Existing sales flow remains unaffected when POS is disabled.
3. Enable/disable can be reversed without data migration.

**Dependencies:** none

### TODO 0.2 - Terminal Registry and Terminal Policy Baseline

**Goal:**  
Create terminal records and policies (business/store scoped) required for session open.

**Related requirements:**  
`FR-5`, `4.4 Admin / Configuration Role`, `WF-1`

**Expected touched areas:**  
- new POS schema: terminals / terminal policies  
- admin terminal management UI/API  
- POS route bootstrap context resolution

**Test cases (Given / When / Then):**
- Given a business terminal is active, when cashier selects it, then session open can proceed.
- Given terminal is disabled, when cashier attempts to use it, then session open is blocked.
- Given terminal policy enforces drawer/printer flags, when POS initializes, then UI reflects available actions.

**Acceptance criteria:**
1. Terminal records are scoped to business.
2. Terminal active/inactive state is enforced at session open.
3. Policy values are retrievable by POS runtime.

**Dependencies:** TODO 0.1

### TODO 0.3 - POS Permissions and Role Mapping Baseline

**Goal:**  
Add POS-specific permissions and map them to cashier/supervisor/admin roles without disturbing existing role behavior.

**Related requirements:**  
`FR-6`, `4.4 Role and Permission Baseline`

**Expected touched areas:**  
- permission seeders  
- authorization gates/policies  
- POS middleware/action checks

**Test cases (Given / When / Then):**
- Given cashier role without supervisor permissions, when attempting price override approval, then action is blocked.
- Given supervisor role with PIN approval permission, when approving override, then action succeeds.
- Given admin role, when accessing terminal/policy config, then access is allowed.

**Acceptance criteria:**
1. POS permissions are explicit (not piggybacked ambiguously on sales permissions).
2. Cashier/supervisor/admin separation matches Section 4.4 baseline.
3. Permission checks are enforced server-side.

**Dependencies:** TODO 0.1

## Milestone 1 - POS Session and Cash Control Core

### TODO 1.1 - POS Session Open / Close Data Model and Services

**Goal:**  
Implement the core POS session lifecycle (`open`, `active`, `closing`, `closed`) with cashier + terminal uniqueness.

**Related requirements:**  
`FR-5`, `WF-1`, `WF-4`

**Expected touched areas:**  
- new POS session tables/models  
- session service (`open`, `close start`, `close finalize`)  
- session status validation middleware

**Test cases (Given / When / Then):**
- Given no active session, when cashier opens session with valid terminal, then active session is created.
- Given an active session for same cashier+terminal, when opening another session, then request is rejected.
- Given active session on terminal A, when same cashier opens on terminal B (policy disallows multi-terminal), then request is rejected.
- Given closed session, when reopening same session ID, then action is rejected.

**Acceptance criteria:**
1. One active session per cashier per terminal is enforced.
2. Session status transitions are valid and auditable.
3. POS sell route requires active session.

**Dependencies:** TODO 0.2, TODO 0.3

### TODO 1.2 - Opening Float Capture (Total + Denominations)

**Goal:**  
Capture opening float with total and optional denomination breakdown per session.

**Related requirements:**  
`FR-5`, `WF-1`, `8.1`

**Expected touched areas:**  
- session cash event schema (opening float event)  
- open-session UI/form + validation  
- cash totals calculator service

**Test cases (Given / When / Then):**
- Given valid total and denominations, when opening session, then opening cash event is recorded and totals match.
- Given total-only input with denomination optional mode enabled, when opening session, then session opens successfully.
- Given denomination sum differs from entered total in strict mode, when opening session, then validation fails.

**Acceptance criteria:**
1. Opening float is mandatory for session open.
2. Denomination capture supports optional mode per policy.
3. Opening event contributes to expected cash calculation.

**Dependencies:** TODO 1.1

### TODO 1.3 - Session Expected Cash Calculator

**Goal:**  
Implement deterministic expected cash-in-drawer calculation from session events.

**Related requirements:**  
`FR-5`, `FR-7`, `6.6`

**Expected touched areas:**  
- cash event ledger  
- expected cash calculator service  
- session summary query/service

**Test cases (Given / When / Then):**
- Given opening float and cash sales, when expected cash is requested, then total equals opening + cash sales.
- Given safe drops and manual adjustments, when expected cash is requested, then total reflects all signed events.
- Given non-cash sales only, when expected cash is requested, then expected cash remains opening float amount.

**Acceptance criteria:**
1. Expected cash is derived from event ledger, not denormalized assumptions only.
2. Calculator is deterministic and test-covered.
3. Session summary exposes expected cash for cashier/supervisor UI.

**Dependencies:** TODO 1.2

### TODO 1.4 - Safe Drop / Cash Pickup Workflow + Supervisor PIN Approval

**Goal:**  
Implement cash pickup workflow with threshold alerts, supervisor approval, and audit logging.

**Related requirements:**  
`FR-5`, `FR-6`, `WF-3`, `8.2`

**Expected touched areas:**  
- cash event types (`safe_drop`)  
- supervisor PIN approval flow  
- threshold monitor service/query  
- pickup UI + audit logs

**Test cases (Given / When / Then):**
- Given expected cash exceeds threshold, when session summary loads, then threshold warning is shown and supervisor monitor flags the session.
- Given cashier initiates pickup and supervisor enters valid PIN, when pickup is confirmed, then safe-drop event is recorded and expected cash decreases.
- Given invalid supervisor PIN, when pickup approval is attempted, then pickup is rejected and no cash event is created.

**Acceptance criteria:**
1. Pickup requires supervisor approval (per baseline policy).
2. Pickup updates expected cash and audit log atomically.
3. Threshold status is queryable for monitoring screens.

**Dependencies:** TODO 1.3, TODO 0.3

### TODO 1.5 - Session Close, Blind Count, Variance Approval Rules

**Goal:**  
Implement close-session flow with blind count, variance calculation, and supervisor approval above threshold.

**Related requirements:**  
`FR-5`, `WF-4`, `8.1`

**Expected touched areas:**  
- session close UI/services  
- counted-cash event / reconciliation record  
- variance threshold policy + approval

**Test cases (Given / When / Then):**
- Given counted cash equals expected cash, when cashier closes session, then session closes without supervisor approval.
- Given variance above threshold, when cashier submits close, then supervisor approval is required before final close.
- Given supervisor approves close with variance, when session closes, then reconciliation result and variance note are recorded.

**Acceptance criteria:**
1. Cashier blind count flow hides expected cash until authorized.
2. Variance threshold rules are enforced.
3. Closed sessions can no longer transact.

**Dependencies:** TODO 1.3, TODO 1.4

## Milestone 2 - POS Checkout Shell and Cart

### TODO 2.1 - POS Shell, Session Guard, and Sell Screen Skeleton

**Goal:**  
Create the POS cashier UI shell with active-session guard and sell screen layout.

**Related requirements:**  
`FR-1`, `NFR-2`, `7.2 Cashier Screens`

**Expected touched areas:**  
- new POS Livewire screens/components  
- POS routes/navigation  
- session guard middleware/component

**Test cases (Given / When / Then):**
- Given no active session, when cashier opens sell screen, then system redirects to session-open flow.
- Given active session, when cashier opens sell screen, then cart/search/payment shortcut areas render.
- Given POS feature disabled, when route is accessed, then UI shell does not load.

**Acceptance criteria:**
1. POS shell renders under feature and session guards.
2. Sell screen supports keyboard and touch layout primitives.
3. No transaction posting occurs from shell-only state.

**Dependencies:** Milestone 1 complete enough for session guard

### TODO 2.2 - Product Search/Scan (Barcode + SKU + Name)

**Goal:**  
Implement fast product lookup for cashier flow using barcode, SKU, and name search.

**Related requirements:**  
`FR-1`, `3.11 search by barcode/SKU/name`, `NFR-2`

**Expected touched areas:**  
- POS product search component/service  
- query/index tuning (if needed)  
- barcode input handling

**Test cases (Given / When / Then):**
- Given exact barcode match, when barcode is scanned, then product is added (or selected) immediately.
- Given SKU query, when cashier searches, then matching products are returned.
- Given name fragment, when cashier searches, then results are ordered predictably and limited for performance.
- Given serial-tracked product, when selected, then cart line flags serial input requirement.

**Acceptance criteria:**
1. Barcode/SKU/name search paths are supported.
2. Search results are scoped to active business and allowed products.
3. UI latency is acceptable for cashier flow (validated in manual UAT).

**Dependencies:** TODO 2.1

### TODO 2.3 - Cart Model, Totals, Discounts, and Tax Display

**Goal:**  
Implement POS cart state with real-time totals, line/bill discounts, and compact routing/tax status indicators.

**Related requirements:**  
`FR-1`, `FR-3`, `NFR-2`

**Expected touched areas:**  
- POS cart state service/component  
- pricing/discount computation adapter (reusing existing logic where possible)  
- tax snapshot preparation

**Test cases (Given / When / Then):**
- Given multiple items, when quantities change, then subtotal and totals recalculate correctly.
- Given line and bill discounts, when applied, then totals reflect stacked rules per policy.
- Given supervisor approval required for price override, when cashier edits price without approval, then change is blocked/pending approval.

**Acceptance criteria:**
1. Cart totals are deterministic and test-covered.
2. Line and bill discounts supported.
3. Price override path is permission/approval gated.

**Dependencies:** TODO 2.2, TODO 3.3 (PIN approvals can be stubbed first)

### TODO 2.4 - Walk-In Customer and Customer Selection Rules

**Goal:**  
Support walk-in/default customer while still allowing customer selection/search when needed.

**Related requirements:**  
`FR-1`, `Batch B defaults (walk-in yes, customer optional)`

**Expected touched areas:**  
- POS customer selector component  
- default guest customer resolution/configuration  
- validation rules in finalization service

**Test cases (Given / When / Then):**
- Given no customer selected, when checkout starts, then system uses default walk-in customer.
- Given cashier selects named customer, when checkout finalizes, then sale posts to that customer.
- Given walk-in customer mapping is missing for business, when checkout attempts finalization, then clear configuration error is shown.

**Acceptance criteria:**
1. Walk-in/default customer path exists and is business-scoped.
2. Customer selection remains optional for cashier.
3. Finalization service always resolves a valid customer ID.

**Dependencies:** TODO 2.1

## Milestone 3 - Hybrid Posting and Immediate Stock Deduction

### TODO 3.1 - POS Checkout Finalization Service Skeleton + Idempotency

**Goal:**  
Create the application service that finalizes checkout on payment confirmation with idempotency protection.

**Related requirements:**  
`FR-1`, `NFR-1`, `6.3`

**Expected touched areas:**  
- new `FinalizePosCheckout` application service  
- idempotency table/service  
- POS checkout request/response contract  
- transaction boundary orchestration

**Test cases (Given / When / Then):**
- Given valid checkout payload, when finalization is called once, then service posts exactly one transaction result.
- Given duplicate submission with same idempotency key, when finalization is retried, then service returns same result and does not duplicate sales/payment/dispatch rows.
- Given exception after sale create but before commit, when transaction rolls back, then no partial posted records remain.

**Acceptance criteria:**
1. Finalization is wrapped in DB transaction.
2. Idempotency key is mandatory and enforced.
3. Failure path is logged and observable.

**Dependencies:** TODO 1.x core session, TODO 2.x cart input contract

### TODO 3.2 - Stock Source Resolver (Configured Locations + Fallback)

**Goal:**  
Resolve stock deduction sources using configured sales locations, including borrowed locations, with priority and fallback.

**Related requirements:**  
`FR-2`, `6.4`

**Expected touched areas:**  
- POS stock source resolver service (may wrap `SalesLocationResolver`)  
- stock availability queries  
- location split allocation logic

**Test cases (Given / When / Then):**
- Given enough stock in preferred location, when resolver runs, then allocation uses only preferred location.
- Given insufficient stock in preferred and enough in second configured location, when resolver runs, then allocation splits/falls back correctly.
- Given borrowed location configured and available, when resolver runs, then borrowed source may be selected.
- Given total stock insufficient across all allowed locations, when resolver runs, then checkout is blocked with clear stock error.

**Acceptance criteria:**
1. Resolver respects configured priority order.
2. Borrowed locations are included only when configuration permits.
3. Allocation output is explicit per line/serial for downstream posting.

**Dependencies:** TODO 0.1, TODO 0.2

### TODO 3.3 - Supervisor PIN Approval Service (Price/Discount/Void)

**Goal:**  
Implement reusable supervisor PIN approval flow for cashier overrides.

**Related requirements:**  
`FR-3`, `FR-6`, `6.5`

**Expected touched areas:**  
- supervisor PIN validation service  
- approval log table/service  
- POS UI modal/approval interaction

**Test cases (Given / When / Then):**
- Given valid supervisor PIN and permission, when approving price override, then approval token/log is issued.
- Given invalid PIN, when approval attempted, then override remains blocked.
- Given supervisor lacks permission for requested action, when PIN is valid, then approval is still denied.

**Acceptance criteria:**
1. PIN approval validates both identity and permission.
2. Approval events are logged with target/action metadata.
3. Approval result can be consumed by multiple POS actions.

**Dependencies:** TODO 0.3

### TODO 3.4 - Hybrid Sale Posting Adapter (Sales + Dispatch + Payment)

**Goal:**  
Bridge POS finalization output into existing sales/dispatch/payment posting with immediate deduction semantics.

**Related requirements:**  
`FR-1`, `FR-2`, `FR-4`, `FR-7`, `6.2`, `6.3`

**Expected touched areas:**  
- POS-to-sales posting adapter  
- sale creation integration  
- dispatch creation/approval integration or immediate dispatch posting path  
- sale payment posting integration

**Test cases (Given / When / Then):**
- Given a standard-item checkout, when finalization completes, then `sale`, stock deduction (`dispatch`/detail), and `sale_payment` are posted consistently.
- Given multi-location allocation, when finalization completes, then posted deduction records preserve source-location attribution.
- Given non-cash payment, when finalization completes, then payment reference is persisted.
- Given failure in payment posting step, when finalization aborts, then sale/dispatch changes are rolled back.

**Acceptance criteria:**
1. Shared posting path produces reconcilable records.
2. Source-location data is preserved for tax/reporting.
3. Rollback prevents partial posting.

**Dependencies:** TODO 3.1, TODO 3.2

### TODO 3.5 - Tax-by-Source Snapshot and Line Attribution

**Goal:**  
Persist line-level tax outcomes based on deducted source location owner settings at checkout time.

**Related requirements:**  
`FR-3`, `FR-7`, `6.4`

**Expected touched areas:**  
- tax policy resolution in finalization  
- line payload snapshot fields  
- reporting cross-reference metadata

**Test cases (Given / When / Then):**
- Given mixed PKP/non-PKP source allocations in one checkout, when finalization posts, then each affected line/portion uses source-derived tax policy snapshot.
- Given business tax configuration changes after checkout, when historical transaction is viewed/reported, then posted tax values remain unchanged.

**Acceptance criteria:**
1. Tax policy is derived from deducted source owner.
2. Tax snapshots are persisted at posting time.
3. Historical reporting does not recalculate from mutable configuration.

**Dependencies:** TODO 3.2, TODO 3.4

### TODO 3.6 - Serial-Tracked Product Validation Before Payment Confirm

**Goal:**  
Require serial assignment and validation before payment confirmation for serial-tracked items.

**Related requirements:**  
`FR-2`, `FR-1`

**Expected touched areas:**  
- POS serial input UI  
- serial validation integration  
- finalization pre-checks

**Test cases (Given / When / Then):**
- Given serial-tracked item without serial selection, when cashier attempts payment, then checkout is blocked.
- Given invalid/already-used serial, when validating serial, then checkout is blocked with clear message.
- Given valid serials for all serial-tracked lines, when finalization runs, then checkout proceeds.

**Acceptance criteria:**
1. Serial requirement enforced before payment confirm.
2. Validation errors are cashier-readable.
3. Finalization uses validated serial assignments.

**Dependencies:** TODO 2.2, TODO 3.1

## Milestone 4 - Payments, Receipt, and Cashier Finish Flow

### TODO 4.1 - Payment Screen (Cash / Transfer / QRIS) + Validation

**Goal:**  
Implement phase-1 payment screen with full-payment requirement and non-cash reference capture.

**Related requirements:**  
`FR-4`, `WF-2`

**Expected touched areas:**  
- POS payment UI/component  
- payment input validation  
- method configuration loader (business-scoped)

**Test cases (Given / When / Then):**
- Given cash payment equal to total, when confirming, then finalization proceeds.
- Given cash payment greater than total, when confirming, then change is computed and finalization proceeds.
- Given transfer/QRIS without reference, when confirming, then validation fails.
- Given partial payment amount, when confirming, then validation fails in phase 1.

**Acceptance criteria:**
1. Full-payment-only rule enforced in phase 1.
2. Non-cash reference is required.
3. Payment payload is ready for finalization service contract.

**Dependencies:** TODO 2.3, TODO 3.1

### TODO 4.2 - Receipt Numbering, Printing, and Reprint Log

**Goal:**  
Generate receipts using business-configured numbering and support network thermal print + reprint logging.

**Related requirements:**  
`FR-4`, `FR-7`, `Receipt number config by business`

**Expected touched areas:**  
- receipt number generator/config adapter  
- print service adapter (network thermal)  
- POS receipt print/reprint logs

**Test cases (Given / When / Then):**
- Given business-specific receipt numbering config, when checkout completes, then receipt number follows that configuration.
- Given printer configured and reachable (simulated), when printing receipt, then print job is recorded and success response returned.
- Given cashier reprints receipt, when permission allows, then reprint action is logged.

**Acceptance criteria:**
1. Receipt numbering is business-configurable.
2. Print/reprint actions are auditable.
3. Print failure does not duplicate financial posting.

**Dependencies:** TODO 4.1, TODO 3.4

### TODO 4.3 - Cash Drawer Adapter Hooks (Optional per Terminal Policy)

**Goal:**  
Implement drawer-open hooks for supported terminals without making drawer availability a hard dependency.

**Related requirements:**  
`FR-5`, `8.1`, `8.2`

**Expected touched areas:**  
- hardware adapter interface  
- terminal policy flags  
- event hooks (session open, cash sale, pickup, close)

**Test cases (Given / When / Then):**
- Given terminal policy enables drawer and adapter is available, when cash sale completes, then drawer open command is attempted and logged.
- Given no drawer support for terminal, when cash sale completes, then transaction succeeds without drawer action.
- Given drawer command fails, when hook runs, then failure is logged and cashier can continue according to policy.

**Acceptance criteria:**
1. Drawer integration is optional and policy-driven.
2. Drawer failures do not corrupt transaction/session data.
3. Hook points exist for open/sale/pickup/close events.

**Dependencies:** TODO 0.2, TODO 4.1

## Milestone 5 - Supervisor Monitoring, Reports, and Reconciliation

### TODO 5.1 - Live Session Monitor and Threshold Dashboard

**Goal:**  
Provide supervisor visibility into active sessions, expected cash, and threshold breaches.

**Related requirements:**  
`FR-5`, `FR-7`, `7.2 Supervisor Screens`

**Expected touched areas:**  
- supervisor dashboard UI  
- session summary query services  
- threshold alert query

**Test cases (Given / When / Then):**
- Given multiple active sessions, when supervisor opens monitor, then each session shows status and expected cash.
- Given threshold-breached session, when monitor loads, then breach is highlighted.
- Given safe drop event recorded, when monitor refreshes, then expected cash updates accordingly.

**Acceptance criteria:**
1. Monitor lists active sessions with current threshold state.
2. Data source comes from POS session + cash event ledger.
3. Access restricted to supervisor/manager roles.

**Dependencies:** Milestone 1 complete

### TODO 5.2 - POS Reporting Pack (Phase 1)

**Goal:**  
Deliver phase-1 reports: daily sales, cashier summary, payment method summary, item sales, voids.

**Related requirements:**  
`FR-7`, `3.12`

**Expected touched areas:**  
- report queries/controllers/views/exports  
- joins between POS cross-reference IDs and sales/payment data  
- filters by business, session, cashier, date

**Test cases (Given / When / Then):**
- Given posted POS transactions, when daily sales report runs, then totals match posted sales/payment records.
- Given mixed payment methods, when payment method summary runs, then method totals match transactions.
- Given void actions, when void report runs, then void events and approvers are listed.

**Acceptance criteria:**
1. Required phase-1 reports are available.
2. Totals reconcile with source posting data.
3. POS-specific metrics (cash exposure/pickups) remain in POS reporting sources.

**Dependencies:** TODO 3.4, TODO 5.1

### TODO 5.3 - Reconciliation Views (POS Session vs Posted Sales/Payments)

**Goal:**  
Provide support/admin tools to reconcile POS session totals with posted sales and payment records.

**Related requirements:**  
`FR-7`, `6.6`, `6.8 Release Validation Checklist`

**Expected touched areas:**  
- reconciliation query/report  
- cross-reference ID storage/read models  
- support/admin diagnostic UI

**Test cases (Given / When / Then):**
- Given completed session with cash and non-cash sales, when reconciliation view loads, then posted totals align with POS session aggregates.
- Given intentionally injected mismatch fixture, when reconciliation view loads, then mismatch is flagged for investigation.

**Acceptance criteria:**
1. Cross-reference IDs support traceability from POS checkout to sales/dispatch/payment.
2. Reconciliation report flags mismatches explicitly.
3. Report is usable as release/UAT validation aid.

**Dependencies:** TODO 3.4, TODO 4.2

## Milestone 6 - Hardening, UAT, and Controlled Enablement

### TODO 6.1 - POS Critical-Path Automated Test Suite

**Goal:**  
Curate and enforce a POS MVP regression suite covering checkout, routing, tax, session, approvals, and reconciliation.

**Related requirements:**  
`NFR-5`, `6.8 Release Validation Checklist`

**Expected touched areas:**  
- feature/integration tests  
- test fixtures/factories for multi-business, locations, serials, tax modes  
- CI/test command documentation

**Test cases (Given / When / Then):**
- Covered by `docs/pos/pos-mvp-test-matrix.md` minimum automated set.

**Acceptance criteria:**
1. Critical-path suite runs reliably in CI/local.
2. Suite covers multi-location + tax-source scenarios.
3. Unit/integration gates are documented for release.

**Dependencies:** All core milestones

### TODO 6.2 - UAT Script and Parallel-Run SOP

**Goal:**  
Document and execute user acceptance test runs plus operational SOP to avoid duplicate sales during parallel run.

**Related requirements:**  
`5.4 Rollout Strategy`, `FR-8 fallback`, `6.7 risks`

**Expected touched areas:**  
- UAT checklist doc  
- cashier/supervisor SOP for parallel run  
- rollback steps verification

**Test cases (Given / When / Then):**
- Given POS enabled for a business, when parallel-run SOP is followed, then no transaction is entered twice.
- Given POS issue detected, when rollback SOP is executed, then team returns to current sales/manual flow without data corruption.

**Acceptance criteria:**
1. UAT scenarios are executed and signed off.
2. Parallel-run SOP and fallback SOP are documented and trained.
3. Rollback feature flag path is verified.

**Dependencies:** MVP functional milestones substantially complete

### TODO 6.3 - Progressive Enablement Across Businesses

**Goal:**  
Enable POS by business in a controlled sequence while keeping final scope as all businesses.

**Related requirements:**  
`5.4 Rollout Strategy`, `6.7 Risk mitigations`

**Expected touched areas:**  
- feature flag operations checklist  
- production monitoring / support runbook  
- business enablement logs

**Test cases (Given / When / Then):**
- Given POS is enabled for a business, when activation checklist completes, then business is marked active with validation evidence.
- Given severe issue after activation, when feature flag is disabled, then POS routes stop and fallback flow resumes.

**Acceptance criteria:**
1. Activation checklist exists and is repeatable.
2. Per-business enable/disable operation is verified.
3. Support team has escalation contacts and rollback steps.

**Dependencies:** TODO 6.1, TODO 6.2

## Cross-Cutting Story List (Reference IDs)

Use these IDs in tickets/PRs/tests to map work back to the requirements baseline.

- `POS-MVP-001` Feature flag + enablement controls
- `POS-MVP-002` Terminal registry + policy
- `POS-MVP-003` POS permissions and role mapping
- `POS-MVP-004` Session lifecycle
- `POS-MVP-005` Opening float + denomination support
- `POS-MVP-006` Expected cash calculator
- `POS-MVP-007` Safe drop workflow + approval
- `POS-MVP-008` Session close and reconciliation
- `POS-MVP-009` POS shell + session guard
- `POS-MVP-010` Product search/scan
- `POS-MVP-011` Cart totals/discount/tax display
- `POS-MVP-012` Walk-in/default customer
- `POS-MVP-013` Checkout finalization + idempotency
- `POS-MVP-014` Stock source resolver
- `POS-MVP-015` Supervisor PIN approval service
- `POS-MVP-016` Hybrid posting adapter
- `POS-MVP-017` Tax-by-source snapshot
- `POS-MVP-018` Serial validation before payment
- `POS-MVP-019` Payment screen (cash/transfer/QRIS)
- `POS-MVP-020` Receipt numbering/printing/reprint log
- `POS-MVP-021` Cash drawer adapter hooks (optional)
- `POS-MVP-022` Live session monitor
- `POS-MVP-023` Phase-1 reporting pack
- `POS-MVP-024` Reconciliation views
- `POS-MVP-025` POS critical-path test suite
- `POS-MVP-026` UAT + parallel-run SOP
- `POS-MVP-027` Progressive business enablement

## Suggested Initial Sprint Slice (Smallest Useful Vertical Path)

If execution starts immediately, build this path first:

1. `POS-MVP-001` Feature flag + enablement
2. `POS-MVP-002` Terminal registry + policy
3. `POS-MVP-003` POS permissions
4. `POS-MVP-004` Session lifecycle
5. `POS-MVP-005` Opening float
6. `POS-MVP-009` POS shell + session guard
7. `POS-MVP-010` Product search/scan
8. `POS-MVP-011` Cart totals
9. `POS-MVP-013` Finalization skeleton + idempotency
10. `POS-MVP-014` Stock source resolver (single-location path first, then fallback)

This slice produces early end-to-end learnings without waiting for all monitoring/reporting features.
