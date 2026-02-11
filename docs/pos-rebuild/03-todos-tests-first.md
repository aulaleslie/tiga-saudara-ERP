# POS Rebuild Phase 3 - TODO Breakdown (Tests-First)

---

## Cross-Cutting Guidelines: Preventing Cascading Test Failures

> **CRITICAL**: Read this section BEFORE implementing ANY TODO item below.
> These guidelines exist because a single removed constant from `ProductSerialNumber.php`
> caused 234 test failures across the entire test suite by breaking a migration that runs
> during test bootstrap. This ensures TODO 4 and future TODOs do not inadvertently break
> other modules' tests.

### Pre-Flight Checklist (Before Each TODO)

Before starting work on any TODO item, complete these checks:

- [ ] **Identify shared models**: List every Eloquent model your change touches. Search for
  all files that reference that model's constants, relations, scopes, and accessors.
  ```bash
  # Example: before modifying ProductSerialNumber
  grep -r "ProductSerialNumber::" --include="*.php" -l
  grep -r "->histories()" --include="*.php" -l
  grep -r "STATUS_ACTIVE\|STATUS_RETURNED\|STATUS_RETURN_IN_PROCESS\|STATUS_BROKEN" --include="*.php" -l
  ```
- [ ] **Check migration references**: Search ALL migration files for references to the model
  or its constants. Migrations run during `RefreshDatabase` test bootstrap; a broken
  migration will fail EVERY database test.
  ```bash
  grep -r "ProductSerialNumber\|SettingSaleLocation" --include="*.php" */Database/Migrations/
  ```
- [ ] **Check accessor/mutator consumers**: If you modify or remove an accessor (e.g.,
  `getStatusAttribute`), find all code that reads `->status` and verify the comparison
  values still match. Accessors that normalize casing (e.g., `strtoupper()`) mean consumers
  MUST use the normalized form (e.g., `'ACTIVE'` not `'active'`).
- [ ] **Check service layer dependencies**: If you remove an import or service call, search
  for other modules that use the same service to ensure audit trail consistency and
  cross-module workflows remain intact.
  ```bash
  grep -r "SerialNumberHistoryService::record" --include="*.php" -l
  ```
- [ ] **Run the full test suite** (`php artisan test`) after every change, not just the
  tests for the module you modified. This catches cascading failures early.

### Shared Model / Constant Impact Analysis

These models and their constants are used across multiple modules. Removing or renaming
any of these WILL cause cascading failures across unrelated tests:

| Model | Shared Elements | Used By (count) |
|-------|-----------------|-----------------|
| `ProductSerialNumber` | `STATUS_ACTIVE`, `STATUS_RETURNED`, `STATUS_RETURN_IN_PROCESS`, `STATUS_BROKEN`, `histories()` relation, `getStatusAttribute()` accessor | 1 migration, 5 controllers, 2 Livewire components, 1 Blade view, 12+ test files |
| `SerialNumberHistory` | `EVENT_RECEIVED`, `EVENT_SOLD`, `EVENT_SALE_RETURNED`, `EVENT_PURCHASE_RETURNED`, `EVENT_REPAIR_RECEIVED`, `EVENT_LOCATION_TRANSFER`, `EVENT_MARKED_BROKEN` | `SerialNumberHistoryService`, 4+ modules (SalesReturn, Purchase, PurchasesReturn, Adjustment), Livewire history table |
| `SettingSaleLocation` | `position` field (ordering), `setting_id`/`location_id` FK relationships | POS location resolution, standard sale scoping, POS location configuration, 2+ test files |

### Test Verification Gates

| Gate | Command | When |
|------|---------|------|
| **Smoke test migrations** | `php artisan migrate:fresh --seed --env=testing` | After modifying any model constant, accessor, or migration file |
| **Full test suite** | `php artisan test` | After every implementation step; before committing |
| **Targeted module** | `php artisan test --filter=SerialNumber` | During development of serial-number-related changes |
| **Check for broken migrations** | `php artisan migrate:status` | After adding or renaming migration files |

### Rules for Modifying Shared Models

1. **NEVER remove a constant** without first searching the entire codebase for references.
   Use `grep -r "ClassName::CONSTANT_NAME" --include="*.php"` across ALL modules, not just
   the current one.

2. **NEVER remove an accessor/mutator** without verifying all consumers use raw DB values
   or adding an equivalent transformation at each call site. Document any breaking changes.

3. **NEVER remove a relationship method** without checking Livewire components, Blade views,
   and test files that call it. Update them in the same commit.

4. **NEVER remove a service call** (e.g., history recording) without checking that the
   audit trail remains complete and consistent across all modules that use it.

5. **Always use model constants** for status comparisons (e.g., `ProductSerialNumber::STATUS_ACTIVE`)
   instead of raw strings (e.g., `'active'` or `'ACTIVE'`). Constants ensure consistency
   if the canonical value ever changes.

6. **Always run the full test suite**, not just the tests in your module. Some tests may
   fail silently in CI but break in local environments if not caught early.

### Example: Why the Constant Removal Caused 234 Failures

The removed constant `ProductSerialNumber::STATUS_RETURN_IN_PROCESS` was referenced in:
- **Migration file** `2026_02_08_000001_backfill_purchase_return_status_normalization.php` at line 62
- When tests run via `php artisan test`, Laravel runs `RefreshDatabase` which executes all migrations
- The migration hit the undefined constant error and crashed
- Since migrations must complete before ANY database test can run, ALL 234 database tests failed
- Root cause: a single constant removal in one model broke tests across the entire test suite

This is why checking migrations first is CRITICAL.

---

## Milestones
- M1: Safe removal / deprecation of old POS dependencies
- M2: Draft sale + code generation
- M3: Cashier checkout + locking
- M4: Drawer sessions
- M5: Multi-location allocation + serial handling
- M6: UX polish + observability + hardening

## M1 - Safe removal / deprecation of old POS dependencies

### TODO 1 — Remove `is_pos` row actions from sales-location configuration UI
**Goal:**
- Remove row-level POS toggle actions and keep only ordering + separate non-row add/remove controls.

**Related requirements:**
- FR-045, FR-046, FR-047, DM-001

**Impacted paths:**
- `Modules/Setting/Resources/views/sale-locations/index.blade.php`
- `Modules/Setting/Http/Controllers/SaleLocationConfigurationController.php`
- `Modules/Setting/Routes/web.php`
- `Modules/Setting/Tests/Feature/SaleLocationConfigurationTest.php`

**Test cases (Given / When / Then):**
- Given user opens `/sales-location-configurations`, when page renders, then no row-level POS toggle action is shown.
- Given user with edit permission, when reordering rows, then order persists and success feedback appears.
- Given user with edit permission, when adding/removing assignment via non-row controls, then assignment updates correctly.
- Given user without edit permission, when performing order/add/remove actions, then response is `403`.

**Unit test plan:**
- Test file name: `Modules/Setting/Tests/Feature/SaleLocationConfigurationTest.php` (extend)
- What to mock: none; use DB-backed feature tests with `RefreshDatabase`
- Assertions:
  - row action text/buttons for POS toggle absent
  - reorder endpoint still works
  - store/destroy endpoints still work through non-row controls
  - forbidden access returns `403`
- Edge cases:
  - empty assignment list
  - first-time setup with only owner location

**Integration / E2E tests (if applicable):**
- Browser flow: admin reorder + add/remove assignment; verify final ordering and persisted assignment rows.

**Implementation outline (NO CODE):**
- Remove toggle form/button from blade.
- Remove/update controller update handler dependencies tied to `is_pos`.
- Keep order/store/destroy flows and feedback messages.
- Update route exposure if `update` endpoint becomes obsolete.

**Definition of Done:**
- No UI row toggle exists.
- Non-row add/remove and reorder work and pass tests.
- Access control behavior unchanged.

### TODO 2 — Refactor POS location resolution to use all configured assignments
**Goal:**
- Make POS location source use all `setting_sale_locations` rows for active setting, ordered by `position`, no `is_pos` filter.

**Related requirements:**
- FR-045, FR-046, FR-047, FR-048, DM-001, DM-002

**Impacted paths:**
- `app/Support/PosLocationResolver.php`
- `Modules/Sale/Http/Controllers/PosController.php`
- `app/Livewire/Pos/Checkout.php`
- `app/Livewire/Pos/ProductList.php`
- `app/Livewire/Pos/SerialNumberPicker.php`
- `app/Livewire/Pos/SessionManager.php`

**Test cases (Given / When / Then):**
- Given active setting has 3 assigned locations, when resolver is called, then all 3 ids are returned in `position ASC, location_id ASC` order.
- Given no assignment for setting, when resolver is called, then empty result is returned and POS submit path fails with deterministic error.
- Given same setup, when standard sale dispatch locations are queried, then they remain setting-owned only.

**Unit test plan:**
- Test file name: `Modules/Sale/Tests/Unit/PosLocationResolverTest.php` (new)
- What to mock: cache facade only if needed; otherwise use actual cache with isolation
- Assertions:
  - resolver ordering behavior
  - no `is_pos` condition dependency
  - cache invalidation on assignment changes still works
- Edge cases:
  - duplicate position values
  - stale session `pos_location_assignment_id`

**Integration / E2E tests (if applicable):**
- Feature test POST POS submit with borrowed location assignment present; verify source locations are considered.

**Implementation outline (NO CODE):**
- Remove `is_pos` where clauses in resolver/controller logic.
- Ensure order tie-breaker logic is explicit.
- Keep standard sale scoping untouched.

**Definition of Done:**
- POS uses assignment list as single source.
- Standard sale scoping regression tests pass.

### TODO 3 — Drop `is_pos` column and clean dependent model/test code
**Goal:**
- Remove schema and model dependencies on `setting_sale_locations.is_pos` in the same delivery.

**Related requirements:**
- FR-047, DM-001

**Impacted paths:**
- `Modules/Setting/Database/Migrations/*` (new drop migration)
- `Modules/Setting/Entities/SettingSaleLocation.php`
- `Modules/Setting/Entities/Location.php`
- `Modules/Setting/Tests/Feature/SaleLocationConfigurationTest.php`

**Test cases (Given / When / Then):**
- Given latest migration state, when migrations run, then no `is_pos` column exists on `setting_sale_locations`.
- Given assignment CRUD operations, when create/update/delete happens, then model hooks still invalidate POS cache.
- Given legacy tests referencing `is_pos`, when test suite runs, then tests are updated and green.

**Unit test plan:**
- Test file name: `Modules/Setting/Tests/Feature/SaleLocationConfigurationTest.php` (refactor existing cases)
- What to mock: none
- Assertions:
  - schema absence for `is_pos`
  - configuration behavior unaffected
  - reorder/add/remove tests pass
- Edge cases:
  - rollback path from new migration
  - existing data with mixed assignments

**Integration / E2E tests (if applicable):**
- Migration test in CI: fresh migrate + seed + configuration page access.

**Implementation outline (NO CODE):**
- Add migration to drop column.
- Remove fillable/cast and changed-field checks for `is_pos`.
- Rewrite tests that assert `is_pos` booleans.

**Definition of Done:**
- Schema no longer has `is_pos`.
- Model and tests have zero `is_pos` dependency.

### TODO 4 — Lock in regression guardrails for standard sale behavior
**Goal:**
- Ensure POS changes do not alter standard sale location scoping and workflows.

**Related requirements:**
- FR-048, Scope out-of-scope standard flow changes

**Impacted paths:**
- `Modules/Sale/Http/Controllers/SaleController.php`
- `Modules/Sale/Tests/Feature/SaleRequestAuthorizationTest.php`
- `Modules/Sale/Tests/Feature/DispatchApprovalTest.php`

**Test cases (Given / When / Then):**
- Given standard sale dispatch page, when loaded, then location options are setting-owned only.
- Given POS location assignments include borrowed locations, when standard sale dispatch runs, then borrowed locations are not accepted.

**Unit test plan:**
- Test file name: `Modules/Sale/Tests/Feature/StandardSaleLocationScopeRegressionTest.php` (new)
- What to mock: none
- Assertions:
  - allowed locations are strict setting-owned list
  - invalid foreign location submit fails validation
- Edge cases:
  - tenant with one location only
  - archived/soft-deleted records

**Integration / E2E tests (if applicable):**
- End-to-end standard sale creation + dispatch with mixed-location tenant setup.

**Implementation outline (NO CODE):**
- Add focused feature tests first.
- Patch only if regression appears.

**Definition of Done:**
- Standard sale scoping proven unchanged by passing regression tests.

## M2 - Draft sale + code generation

### TODO 5 — Introduce POS draft persistence model and lifecycle fields
**Goal:**
- Add persistent POS draft storage supporting status, expiry, lock metadata, and edit ownership.

**Related requirements:**
- FR-001 to FR-006, FR-014, DM-004, DM-005, DM-008

**Impacted paths:**
- `database/migrations/*` (new draft tables/columns)
- `app/Models/*` (new draft model)
- `Modules/Sale/Http/Controllers/PosController.php` (adapter use)

**Test cases (Given / When / Then):**
- Given draft create request, when saved, then status is `Ajukan Pembayaran` and expiry is set.
- Given expired draft, when lookup occurs, then status resolves to expired and payment is blocked.
- Given unpaid draft, when no payment submitted, then no `sales`/`sale_payments` are created.

**Unit test plan:**
- Test file name: `Modules/Sale/Tests/Feature/PosDraftLifecycleTest.php` (new)
- What to mock: time (`Carbon::setTestNow`)
- Assertions:
  - state defaults
  - expiry transitions
  - absence of sales/payment rows pre-submit
- Edge cases:
  - null customer
  - concurrent updates near expiry boundary

**Integration / E2E tests (if applicable):**
- Create draft from POS UI then reload page/session and verify draft persists.

**Implementation outline (NO CODE):**
- Add draft schema + indexes.
- Add lifecycle constants and transition rules.
- Wire controller/service to persist draft instead of immediate sale.

**Definition of Done:**
- Draft lifecycle is persisted and queryable.
- No premature financial/stock effects.

### TODO 6 — Build POS code allocator at draft creation
**Goal:**
- Generate `<pos_document_prefix>-YYYY-MM-00001` on draft creation, unique per setting per month, never reused.

**Related requirements:**
- FR-008 to FR-013, DM-003, DM-008

**Impacted paths:**
- `app/Models/PosReceipt.php` (number strategy handoff)
- `Modules/Setting/Entities/Setting.php`
- `Modules/Setting/Http/Requests/StoreSettingsRequest.php`
- `Modules/Sale/*` (draft creation path)

**Test cases (Given / When / Then):**
- Given setting prefix `POSA`, when first draft created in month, then code is `POSA-YYYY-MM-00001`.
- Given multiple draft creations in same month, when created sequentially, then number increments by 1.
- Given cancelled/expired/void draft, when new draft created, then old number is not reused.
- Given different setting, when draft created same month, then sequence starts independently.

**Unit test plan:**
- Test file name: `Modules/Sale/Tests/Unit/PosCodeAllocatorTest.php` (new)
- What to mock: current time; optional DB transaction lock behavior wrapper
- Assertions:
  - format correctness
  - uniqueness scope by setting+month
  - monotonic increment
- Edge cases:
  - missing/empty `pos_document_prefix`
  - month boundary rollover

**Integration / E2E tests (if applicable):**
- Concurrency integration test using parallel requests to draft creation endpoint.

**Implementation outline (NO CODE):**
- Introduce dedicated allocator service.
- Call allocator at draft create.
- Persist code on draft and carry to final receipt.

**Definition of Done:**
- Draft and final receipt share same code.
- Sequence behavior fully covered by tests.

### TODO 7 — Add draft CRUD contracts (create/get/update) with role gates
**Goal:**
- Provide API/contracts for draft creation, retrieval by code, and pre-submit updates with permission rules.

**Related requirements:**
- FR-014, FR-022 to FR-026, API contracts section

**Impacted paths:**
- `Modules/Sale/Routes/web.php`
- `Modules/Sale/Http/Controllers/PosController.php` (or new draft controller)
- `Modules/Sale/Http/Requests/*` (new draft requests)
- `Modules/User/Database/Seeders/PermissionsTableSeeder.php`

**Test cases (Given / When / Then):**
- Given floor user creates draft, when retrieve by code, then draft payload matches persisted cart.
- Given pay-only cashier updates draft, when request sent, then request is forbidden.
- Given cashier manager updates draft before submit, when request sent, then update succeeds and is audited.
- Given unknown code, when retrieve/update, then `POS_DRAFT_NOT_FOUND` is returned.

**Unit test plan:**
- Test file name: `Modules/Sale/Tests/Feature/PosDraftApiAuthorizationTest.php` (new)
- What to mock: policy/gate only if isolated unit; otherwise permission seed in feature tests
- Assertions:
  - role-based allow/deny
  - response schema and error code payload
  - audit entry creation on update
- Edge cases:
  - editing expired draft
  - editing locked draft owned by other cashier

**Integration / E2E tests (if applicable):**
- Retrieve by numeric/alphanumeric code input path from cashier screen.

**Implementation outline (NO CODE):**
- Add routes and request validators.
- Map permissions to actions.
- Return standardized error payloads.

**Definition of Done:**
- Draft contracts are stable and permission-safe.

### TODO 8 — Ensure final receipt identity reuses draft code
**Goal:**
- Guarantee final POS receipt uses draft code without regeneration and supports print/reprint routes.

**Related requirements:**
- FR-010, FR-011, FR-068 to FR-070, DM-004

**Impacted paths:**
- `app/Models/PosReceipt.php`
- `Modules/Sale/Http/Controllers/PosController.php`
- `Modules/Sale/Routes/web.php`
- `Modules/Sale/Http/Livewire/PosTransactions.php`

**Test cases (Given / When / Then):**
- Given draft code exists, when payment succeeds, then created `pos_receipts.receipt_number` equals draft code.
- Given receipt printed via route, when access from other tenant, then access is forbidden.
- Given reprint-last used, when session has latest receipt, then correct receipt is printed.

**Unit test plan:**
- Test file name: `Modules/Sale/Tests/Feature/PosReceiptIdentityTest.php` (new)
- What to mock: none (DB + route tests)
- Assertions:
  - identity equality draft vs receipt
  - tenant guard on print route
  - reprint-last selects correct receipt
- Edge cases:
  - missing session flash receipt id
  - receipt with multiple sales docs

**Integration / E2E tests (if applicable):**
- Browser print flow from success state and history page.

**Implementation outline (NO CODE):**
- Pass draft code into receipt creation.
- Prevent fallback number generation when explicit code is present.
- Keep print contracts intact.

**Definition of Done:**
- Single POS code identity from draft through receipt print.

## M3 - Cashier checkout + locking

### TODO 9 — Implement lock acquisition/release/heartbeat with 15-minute TTL
**Goal:**
- Enforce single-cashier checkout lock with timeout and heartbeat.

**Related requirements:**
- FR-015 to FR-021, NFR-008

**Impacted paths:**
- `Modules/Sale/*` (new lock service/controller)
- `app/Livewire/Pos/Checkout.php`
- `database/migrations/*` (lock fields)

**Test cases (Given / When / Then):**
- Given draft unlocked, when cashier A locks, then cashier B receives `POS_LOCK_CONFLICT`.
- Given lock active, when heartbeat sent, then lock expiry extends.
- Given lock expires at 15 minutes, when cashier B retries, then lock is acquirable.
- Given manager override, when executed, then lock transfers/releases with audit.

**Unit test plan:**
- Test file name: `Modules/Sale/Tests/Unit/PosDraftLockServiceTest.php` (new)
- What to mock: time provider; optional DB transaction wrapper
- Assertions:
  - lock ownership semantics
  - TTL extension logic
  - override permission gate
- Edge cases:
  - simultaneous lock requests
  - stale heartbeat after lock release

**Integration / E2E tests (if applicable):**
- Two-session integration test simulating two users on same draft.

**Implementation outline (NO CODE):**
- Add lock fields and service.
- Add lock/unlock/heartbeat endpoints.
- Wire checkout UI to call heartbeat.

**Definition of Done:**
- Deterministic single-lock behavior with tested timeout.

### TODO 10 — Enforce role-based mutability during checkout
**Goal:**
- Enforce cashier pay-only immutability and manager full edit authority before submit.

**Related requirements:**
- FR-022 to FR-026, FR-025

**Impacted paths:**
- `app/Livewire/Pos/Checkout.php`
- `Modules/Sale/Http/Requests/*` (update/submit)
- `Modules/User/Database/Seeders/PermissionsTableSeeder.php`

**Test cases (Given / When / Then):**
- Given pay-only cashier, when attempting item/qty/price/customer edit, then operation is denied.
- Given manager, when editing any field before submit, then operation succeeds.
- Given submit has started, when any edit attempt occurs, then `POS_DRAFT_STATE_INVALID` is returned.

**Unit test plan:**
- Test file name: `Modules/Sale/Tests/Feature/PosCheckoutRoleMutabilityTest.php` (new)
- What to mock: permission registrar reset; optional policy checks
- Assertions:
  - forbidden status for pay-only edits
  - allowed manager edits
  - immutable after submit starts
- Edge cases:
  - lock owner mismatch
  - manager edit on expired draft

**Integration / E2E tests (if applicable):**
- UI role-differentiated checkout screens (pay-only controls hidden/disabled).

**Implementation outline (NO CODE):**
- Define permissions for pay-only and manager actions.
- Add field-level guard checks in write paths.
- Add UI disable/hide behavior for forbidden actions.

**Definition of Done:**
- Role rules are server-enforced and UI-consistent.

### TODO 11 — Implement pre-submit void flow
**Goal:**
- Support manager-only void before payment submit; reject void after submit starts.

**Related requirements:**
- FR-007, FR-025, FR-064, error codes section

**Impacted paths:**
- `Modules/Sale/*` (void endpoint/service)
- `app/Livewire/Pos/Checkout.php`
- `Modules/Sale/Tests/*` (new)

**Test cases (Given / When / Then):**
- Given manager and unpaid draft, when void requested, then status becomes voided and draft is non-payable.
- Given pay-only cashier, when void requested, then `POS_PERMISSION_DENIED` returned.
- Given submit already started, when void requested, then request is rejected.

**Unit test plan:**
- Test file name: `Modules/Sale/Tests/Feature/PosVoidFlowTest.php` (new)
- What to mock: none
- Assertions:
  - valid pre-submit void transition
  - permission enforcement
  - post-submit rejection
- Edge cases:
  - void on expired draft
  - double-void request idempotency

**Integration / E2E tests (if applicable):**
- Manager void from cashier screen then recreate new draft flow.

**Implementation outline (NO CODE):**
- Add void transition rule and endpoint.
- Add audit log writes.
- Add UI action visibility by role/state.

**Definition of Done:**
- Void rules are unambiguous and tested.

### TODO 12 — Finalize payment orchestration from draft with idempotency
**Goal:**
- Finalize payment from draft in one transaction, remove hardcoded `'PSL'`, and rely on `Sale` reference generation.

**Related requirements:**
- FR-037, FR-038, FR-042, FR-043, FR-052, DM-007, NFR-005, NFR-006, NFR-007

**Impacted paths:**
- `Modules/Sale/Http/Controllers/PosController.php`
- `Modules/Sale/Http/Requests/StorePosSaleRequest.php`
- `Modules/Sale/Entities/Sale.php`
- `app/Models/PosReceipt.php`

**Test cases (Given / When / Then):**
- Given valid draft and payments, when submit-payment called once, then one receipt + partitioned sales + sale_payments + dispatches are created.
- Given same idempotency key retried, when submit-payment called again, then no duplicate rows are created.
- Given stock failure in any line, when submit-payment called, then transaction rolls back fully.
- Given generated sales docs, when references inspected, then they are generated by `Sale` boot logic (not `'PSL'`).

**Unit test plan:**
- Test file name: `Modules/Sale/Tests/Feature/PosFinalizeAtomicityTest.php` (new)
- What to mock: optional idempotency store; otherwise DB-backed checks
- Assertions:
  - atomic write set completeness
  - retry safety with same key
  - rollback leaves no partial side effects
  - sales reference not equal hardcoded constant
- Edge cases:
  - mixed payment methods with cash overpay
  - multiple owner-setting partitions

**Integration / E2E tests (if applicable):**
- End-to-end checkout with split tender and multi-owner cart.

**Implementation outline (NO CODE):**
- Add idempotency key validation and persistence.
- Shift submit path from cart-immediate flow to draft-finalize flow.
- Remove hardcoded `reference` assignment.

**Definition of Done:**
- Finalization is atomic, idempotent, and reference-correct.

## M4 - Drawer sessions

### TODO 13 — Enforce session gating and lifecycle behavior
**Goal:**
- Enforce active POS session requirement and same-user+same-setting pause/resume behavior across devices.

**Related requirements:**
- FR-027 to FR-031, NFR-009

**Impacted paths:**
- `app/Support/PosSessionManager.php`
- `app/Http/Middleware/EnsureActivePosSession.php`
- `app/Livewire/Pos/SessionManager.php`
- `app/Models/PosSession.php`

**Test cases (Given / When / Then):**
- Given no active session, when accessing POS route, then redirected with session-required error.
- Given paused session, when accessing POS route, then blocked until resume.
- Given same user+same setting on another device, when resume called, then session resumes successfully.
- Given different setting, when resume attempted, then operation is rejected.

**Unit test plan:**
- Test file name: `Modules/Sale/Tests/Feature/PosSessionLifecycleTest.php` (new)
- What to mock: user agent/device name only if needed
- Assertions:
  - middleware gate behavior
  - pause/resume/close transitions
  - same-setting scoping
- Edge cases:
  - closing paused session
  - stale session records

**Integration / E2E tests (if applicable):**
- Two-browser/device simulation for cross-device resume.

**Implementation outline (NO CODE):**
- Keep current scope logic and add explicit tests.
- Patch only discovered gaps.

**Definition of Done:**
- Session lifecycle behavior is locked by tests and aligns with frozen requirements.

### TODO 14 — Cash movement and reconciliation correctness
**Goal:**
- Ensure cash in/out movements, expected cash calculation, and close discrepancy are correct and auditable.

**Related requirements:**
- FR-029, FR-031, FR-032, FR-065, NFR-012

**Impacted paths:**
- `app/Livewire/Pos/CashSettlement.php`
- `app/Livewire/Pos/CashPickup.php`
- `app/Livewire/Pos/CashReconciliation.php`
- `database/migrations/2025_10_05_000001_create_cashier_cash_movements_table.php`

**Test cases (Given / When / Then):**
- Given session with opening cash and cash sales, when expected cash is computed, then value is accurate.
- Given paid-in/out entries, when session closes, then discrepancy reflects all movements.
- Given unauthorized user, when cash movement endpoint invoked, then request denied.

**Unit test plan:**
- Test file name: `Modules/Sale/Tests/Feature/PosCashReconciliationTest.php` (new)
- What to mock: none
- Assertions:
  - expected cash formula
  - discrepancy persistence
  - permission enforcement
- Edge cases:
  - negative/zero movement attempts
  - duplicate close submission

**Integration / E2E tests (if applicable):**
- Shift start -> cash in/out -> close shift -> discrepancy review flow.

**Implementation outline (NO CODE):**
- Add reconciliation feature tests.
- Align calculations and validations if test failures found.

**Definition of Done:**
- Cash movement lifecycle is validated and auditable.

## M5 - Multi-location allocation + serial handling

### TODO 15 — Implement non-serial allocation priority engine
**Goal:**
- Implement deterministic allocation across configured locations with non-tax-first behavior.

**Related requirements:**
- FR-049, FR-050, FR-051, FR-059, FR-060

**Impacted paths:**
- `app/Livewire/Pos/Checkout.php`
- `Modules/Sale/Http/Controllers/PosController.php`
- `Modules/Product/Entities/ProductStock.php`

**Test cases (Given / When / Then):**
- Given requested non-serial qty and mixed stock buckets, when allocation runs, then non-tax is exhausted first across configured order.
- Given same bucket across multiple locations, when allocation runs, then tie-breaker is `position`, then `location_id`.
- Given insufficient total stock, when submit attempted, then `POS_STOCK_INSUFFICIENT` with shortage context.

**Unit test plan:**
- Test file name: `Modules/Sale/Tests/Unit/PosAllocationEngineTest.php` (new)
- What to mock: none; use in-memory fixtures/DTO arrays
- Assertions:
  - exact allocation output per priority
  - deterministic ordering
  - shortage detection
- Edge cases:
  - zero qty request
  - single-location only
  - all stock in tax bucket only

**Integration / E2E tests (if applicable):**
- Full checkout with mixed owner locations and non-serial lines.

**Implementation outline (NO CODE):**
- Extract allocation logic into dedicated service.
- Use service from checkout + finalize path.

**Definition of Done:**
- Allocation behavior matches frozen algorithm and is unit-tested.

### TODO 16 — Enforce serial validation and serial-driven tax/location binding
**Goal:**
- Ensure serial products use serial table as authority for location and tax, with strict availability checks.

**Related requirements:**
- FR-053 to FR-058, FR-061

**Impacted paths:**
- `app/Livewire/Pos/SerialNumberPicker.php`
- `app/Livewire/Pos/Checkout.php`
- `Modules/Sale/Http/Controllers/PosController.php`
- `Modules/Product/Entities/ProductSerialNumber.php`

**Test cases (Given / When / Then):**
- Given serial-required product, when serial count < qty, then submit blocked.
- Given serial with non-active status or in return process, when submit, then `POS_SERIAL_UNAVAILABLE`.
- Given serial location not in POS source, when submit, then `POS_SERIAL_LOCATION_NOT_ALLOWED`.
- Given serial has tax id, when sale detail created, then tax id matches serial tax.

**Unit test plan:**
- Test file name: `Modules/Sale/Tests/Feature/PosSerialValidationTest.php` (new)
- What to mock: none
- Assertions:
  - status/return-process checks
  - location whitelist checks
  - tax binding correctness
- Edge cases:
  - duplicate serial in one draft
  - same serial submitted twice concurrently

**Integration / E2E tests (if applicable):**
- Scan serial flow in POS UI with lock and submit.

**Implementation outline (NO CODE):**
- Centralize serial validation in submit path.
- Keep picker UX and submit validation consistent.

**Definition of Done:**
- Serial constraints are enforced and tested end-to-end.

### TODO 17 — Partition finalized POS into multiple `sales` by owner setting
**Goal:**
- Create one POS receipt with multiple `sales` documents split by owner setting and allocate payments accordingly.

**Related requirements:**
- FR-052, FR-038, FR-042, FR-069

**Impacted paths:**
- `Modules/Sale/Http/Controllers/PosController.php`
- `app/Models/PosReceipt.php`
- `Modules/Sale/Entities/Sale.php`
- `Modules/Sale/Entities/SalePayment.php`

**Test cases (Given / When / Then):**
- Given cart with items sourced from setting A and B, when finalize, then one receipt and two `sales` rows are created with correct `setting_id` partition.
- Given split tender payments, when finalize, then sale payments are distributed across sales without exceeding each sale total.
- Given receipt history view, when opening receipt, then linked sales documents are visible and consistent.

**Unit test plan:**
- Test file name: `Modules/Sale/Tests/Feature/PosMultiOwnerPartitionTest.php` (new)
- What to mock: none
- Assertions:
  - partition correctness by owner setting
  - payment distribution totals
  - `pos_receipt_id` links on `sales` and `sale_payments`
- Edge cases:
  - one-owner-only cart
  - three-owner mixed cart

**Integration / E2E tests (if applicable):**
- Checkout with mixed stock origins and print receipt verification.

**Implementation outline (NO CODE):**
- Keep partitioning by owner setting in finalize service.
- Preserve payment allocation rules and due calculations.

**Definition of Done:**
- Multi-owner partition and financial links are correct and verifiable.

## M6 - UX polish + observability + hardening

### TODO 18 — Standardize POS error contracts and mappings
**Goal:**
- Enforce stable error payload and frozen error codes across POS endpoints.

**Related requirements:**
- Validation/error section, NFR-014

**Impacted paths:**
- `app/Exceptions/Handler.php`
- `Modules/Sale/Http/Controllers/*`
- `Modules/Sale/Http/Requests/*`

**Test cases (Given / When / Then):**
- Given known business error (lock conflict), when endpoint fails, then payload includes `code`, `message`, `details`, `trace_id`.
- Given validation failure (non-cash overpay), when submit attempted, then mapped POS code is returned.
- Given unexpected exception, when thrown, then trace id exists and no sensitive stack is leaked.

**Unit test plan:**
- Test file name: `Modules/Sale/Tests/Feature/PosErrorContractTest.php` (new)
- What to mock: exception throw path for generic failure
- Assertions:
  - payload shape consistency
  - code mapping correctness
  - trace id presence
- Edge cases:
  - nested validation errors
  - locale/message differences

**Integration / E2E tests (if applicable):**
- UI error toast rendering from standardized API error payloads.

**Implementation outline (NO CODE):**
- Create POS error mapper layer.
- Update endpoints to use standardized contract.

**Definition of Done:**
- All POS endpoints return consistent error contract.

### TODO 19 — Receipt/history traceability and authorization hardening
**Goal:**
- Ensure receipt print/history remain tenant-safe and include linked sales traceability.

**Related requirements:**
- FR-068 to FR-071, NFR-010

**Impacted paths:**
- `Modules/Sale/Routes/web.php`
- `Modules/Sale/Http/Livewire/PosTransactions.php`
- `Modules/Sale/Resources/views/print-pos.blade.php`

**Test cases (Given / When / Then):**
- Given receipt belongs to different setting, when print route accessed, then response is `403`.
- Given valid receipt with multi-sales links, when printed/viewed, then linked references are shown.
- Given transactions history filters, when filtering by session/date, then only expected records appear.

**Unit test plan:**
- Test file name: `Modules/Sale/Tests/Feature/PosReceiptAuthorizationAndTraceabilityTest.php` (new)
- What to mock: none
- Assertions:
  - tenant authorization on print
  - linked sales visibility
  - history filtering correctness
- Edge cases:
  - receipt without linked sales
  - archived linked sale rows

**Integration / E2E tests (if applicable):**
- Print from history list and success page, verify same document identity.

**Implementation outline (NO CODE):**
- Add tests around existing print/history routes.
- Patch view/query behavior if any gap appears.

**Definition of Done:**
- Print/history are secure and traceable across split-sales receipts.

### TODO 20 — Observability, metrics, and performance guardrails
**Goal:**
- Add operational visibility and measurable performance checks for critical POS paths.

**Related requirements:**
- NFR-001 to NFR-004, NFR-012, NFR-013

**Impacted paths:**
- `Modules/Sale/Http/Controllers/PosController.php`
- `app/Support/*` (logging/metrics helper)
- `config/logging.php` (if needed)
- `Modules/Sale/Tests/Feature/*` (performance/assertion scaffolding)

**Test cases (Given / When / Then):**
- Given draft create/lock/submit actions, when executed, then structured logs include `setting_id`, `user_id`, `pos_code`, `trace_id`.
- Given lock timeout event, when it occurs, then corresponding metric increment is emitted.
- Given high-volume lookup benchmark, when executed in CI profile, then p95 threshold checks are reported.

**Unit test plan:**
- Test file name: `Modules/Sale/Tests/Unit/PosObservabilityTest.php` (new)
- What to mock: logger and metrics emitter interfaces
- Assertions:
  - expected fields on log payloads
  - metric names and labels emitted
  - no sensitive payload leakage
- Edge cases:
  - missing `pos_code` on early failures
  - repeated idempotent submit logging dedupe

**Integration / E2E tests (if applicable):**
- CI smoke benchmark script for draft lookup and finalize path timings.

**Implementation outline (NO CODE):**
- Define minimal POS telemetry schema.
- Instrument draft, lock, submit, and error paths.
- Add CI performance assertions (non-blocking first, then blocking after baseline).

**Definition of Done:**
- Required logs/metrics exist and are test-covered.
- Performance guardrails are measurable in CI.
