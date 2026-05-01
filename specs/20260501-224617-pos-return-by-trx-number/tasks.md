# Tasks: POS Return by Transaction Number

**Input**: Design documents from `/specs/20260501-224617-pos-return-by-trx-number/`
**Prerequisites**: `plan.md`, `spec.md`, `research.md`, `data-model.md`, `contracts/pos-return-contract.md`, `quickstart.md`
**Tests**: Included because the specification, quickstart, and research require focused automated verification for authorization, lookup, split-owner mapping, bundle rules, lifecycle guards, and quantity caps.
**Organization**: Tasks are grouped by user story so each story can be implemented and tested independently after the foundational phase.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel because it touches a different file or has no dependency on incomplete tasks
- **[Story]**: User story label for story-phase tasks only
- Every task includes an exact target file path

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Prepare shared POS return directories, permissions, and navigation surfaces.

- [ ] T001 [P] Create POS return directories in `app/Support/PosReturn/`, `app/Livewire/PosReturn/`, `resources/views/livewire/pos-return/`, and `Modules/Pos/Resources/views/returns/`
- [ ] T002 [P] Register `pos.returns.view`, `pos.returns.create`, `pos.returns.edit`, `pos.returns.delete`, `pos.returns.approve`, `pos.returns.receive`, and `pos.returns.dispatch` labels in `app/Config/Permissions.php`
- [ ] T003 [P] Add POS return role bundles and capability cluster entries in `Modules/Pos/Support/PosPermissionMatrix.php`
- [ ] T004 [P] Add POS return sidebar visibility and active-state rules in `resources/views/layouts/menu.blade.php`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Add schema, entities, shared service scaffolding, and route contracts required by all user stories.

**Critical**: No user story implementation should begin until this phase is complete.

- [ ] T005 Create `pos_returns` migration with snapshot, lifecycle, audit, transaction, checkout, customer, option, totals, and indexed lookup columns in `Modules/Pos/Database/Migrations/2026_05_01_000001_create_pos_returns_table.php`
- [ ] T006 Create `pos_return_lines` migration with sale, dispatch, owner, location, tax, serial, bundle, quantity, and money columns in `Modules/Pos/Database/Migrations/2026_05_01_000002_create_pos_return_lines_table.php`
- [ ] T007 Create Sales Return POS linkage migration for `pos_return_id`, POS source metadata, and indexes in `Modules/Pos/Database/Migrations/2026_05_01_000003_add_pos_return_link_columns_to_sale_returns_table.php`
- [ ] T008 Create Sale Return Detail POS linkage migration for `pos_return_line_id` and `bundle_group_key` in `Modules/Pos/Database/Migrations/2026_05_01_000004_add_pos_return_line_link_columns_to_sale_return_details_table.php`
- [ ] T083 [P] Ensure POS return table migrations are generated/applied through Laravel migration tooling, use MySQL-compatible column/index definitions, and include reversible `down()` drops in `Modules/Pos/Database/Migrations/2026_05_01_000001_create_pos_returns_table.php` and `Modules/Pos/Database/Migrations/2026_05_01_000002_create_pos_return_lines_table.php`
- [ ] T084 [P] Ensure Sales Return linkage migrations use nullable/default-compatible columns for historical rows, dependency-safe index drops, and reversible `down()` rollback in `Modules/Pos/Database/Migrations/2026_05_01_000003_add_pos_return_link_columns_to_sale_returns_table.php` and `Modules/Pos/Database/Migrations/2026_05_01_000004_add_pos_return_line_link_columns_to_sale_return_details_table.php`
- [ ] T009 [P] Implement `PosReturn` model casts, constants, scopes, and relationships in `Modules/Pos/Entities/PosReturn.php`
- [ ] T010 [P] Implement `PosReturnLine` model casts, constants, and relationships in `Modules/Pos/Entities/PosReturnLine.php`
- [ ] T011 Extend `SaleReturn` with POS return relationships, fillable/cast support, and source metadata accessors in `Modules/SalesReturn/Entities/SaleReturn.php`
- [ ] T012 Extend `SaleReturnDetail` with POS return line relationship and bundle group casts in `Modules/SalesReturn/Entities/SaleReturnDetail.php`
- [ ] T013 [P] Add `posReturn()` and `posReturnLines()` relationship coverage tests in `Modules/Pos/Tests/Feature/POSReturnModelRelationshipTest.php`
- [ ] T014 [P] Add POS return permission configuration and role matrix tests in `Modules/Pos/Tests/Feature/POSReturnPermissionMatrixTest.php`
- [ ] T015 Implement `PosReturnQuantityGuard` skeleton for active cumulative quantity checks by dispatch detail in `app/Support/PosReturn/PosReturnQuantityGuard.php`
- [ ] T016 Implement `PosReturnSnapshotService` skeleton for transaction/receipt lookup, scope validation, canonical snapshot payload, and hash generation in `app/Support/PosReturn/PosReturnSnapshotService.php`
- [ ] T017 Implement `PosReturnSubmissionService` skeleton for snapshot validation, line normalization, Sales Return creation hooks, and POS Return persistence in `app/Support/PosReturn/PosReturnSubmissionService.php`
- [ ] T018 Implement `PosReturnLifecycleService` skeleton for approve, reject, receive, cash refund, replacement dispatch, and wrapper status sync in `app/Support/PosReturn/PosReturnLifecycleService.php`
- [ ] T019 Register POS return routes matching `contracts/pos-return-contract.md` in `Modules/Pos/Routes/web.php`
- [ ] T020 Implement empty controller actions with permission gates and dependency injection in `Modules/Pos/Http/Controllers/PosReturnController.php`

**Checkpoint**: Database, entities, permissions, routes, and service entry points exist for story implementation.

---

## Phase 3: User Story 1 - Start POS Return from Transaction Number (Priority: P1) MVP

**Goal**: A permitted user can enter a completed POS transaction code or receipt number and view an immutable source snapshot for the exact POS sale.

**Independent Test**: Enter valid, unknown, unposted, cancelled, out-of-scope, and fully returned POS transaction numbers; verify only valid posted transactions populate snapshot data with generated sales, owner groups, dispatch state, returnable lines, payment summary, and snapshot hash.

### Tests for User Story 1

- [ ] T021 [P] [US1] Add route authorization tests for `/pos/returns`, `/pos/returns/create`, and `/pos/returns/lookup` in `Modules/Pos/Tests/Feature/POSReturnRouteAuthorizationTest.php`
- [ ] T022 [P] [US1] Add successful transaction-code and receipt-number lookup tests in `Modules/Pos/Tests/Feature/POSReturnLookupTest.php`
- [ ] T023 [P] [US1] Add blocked lookup tests for unknown, non-posted, cancelled, cross-setting, and fully returned transactions in `Modules/Pos/Tests/Feature/POSReturnLookupTest.php`
- [ ] T024 [P] [US1] Add Livewire snapshot rendering tests for header, owner groups, payments, dispatch status, returnable lines, and hash in `tests/Feature/Livewire/PosReturn/PosReturnCreateFormTest.php`

### Implementation for User Story 1

- [ ] T025 [US1] Implement completed POS transaction and receipt lookup with setting visibility in `app/Support/PosReturn/PosReturnSnapshotService.php`
- [ ] T026 [US1] Build snapshot owner group and generated sale mapping from `PosCheckoutSale` records in `app/Support/PosReturn/PosReturnSnapshotService.php`
- [ ] T027 [US1] Build returnable line snapshot from generated sales, dispatch details, sale details, serials, and existing return quantities in `app/Support/PosReturn/PosReturnSnapshotService.php`
- [ ] T028 [US1] Add stable canonical snapshot hash generation and stale snapshot metadata in `app/Support/PosReturn/PosReturnSnapshotService.php`
- [ ] T029 [US1] Implement `lookup()` endpoint response and failure messages in `Modules/Pos/Http/Controllers/PosReturnController.php`
- [ ] T030 [P] [US1] Implement POS return list Livewire component for lookup-independent index display in `app/Livewire/PosReturn/PosReturnTable.php`
- [ ] T031 [US1] Implement transaction number lookup state and validation in `app/Livewire/PosReturn/PosReturnCreateForm.php`
- [ ] T032 [US1] Render lookup form, source snapshot, owner groups, payment summary, and returnable lines in `resources/views/livewire/pos-return/pos-return-create-form.blade.php`
- [ ] T033 [P] [US1] Create POS return index page using the Livewire table in `Modules/Pos/Resources/views/returns/index.blade.php`
- [ ] T034 [P] [US1] Create POS return create page using the Livewire create form in `Modules/Pos/Resources/views/returns/create.blade.php`

**Checkpoint**: User Story 1 is functional and testable without submitting a return.

---

## Phase 4: User Story 2 - Submit Return for Cash or Product Replacement (Priority: P2)

**Goal**: A permitted user can select return quantities, choose cash return or product replacement, and submit POS Return records with owner/sale-aligned Sales Return records or lines.

**Independent Test**: Create cash and replacement returns for normal and bundle POS lines; verify persisted POS Return data, linked Sales Return data, bundle component expansion, stale snapshot blocking, and quantity caps.

### Tests for User Story 2

- [ ] T035 [P] [US2] Add cash return submission tests for non-bundle POS lines in `Modules/Pos/Tests/Feature/POSReturnSubmissionTest.php`
- [ ] T036 [P] [US2] Add product replacement submission tests for non-bundle POS lines in `Modules/Pos/Tests/Feature/POSReturnSubmissionTest.php`
- [ ] T037 [P] [US2] Add stale snapshot hash rejection tests in `Modules/Pos/Tests/Feature/POSReturnSnapshotStalenessTest.php`
- [ ] T038 [P] [US2] Add bundle return expansion and parent-only rejection tests in `Modules/Pos/Tests/Feature/POSReturnBundleSubmissionTest.php`
- [ ] T039 [P] [US2] Add cumulative dispatch quantity cap tests for partial and duplicate returns in `Modules/Pos/Tests/Feature/POSReturnQuantityGuardTest.php`
- [ ] T040 [P] [US2] Add Livewire quantity, option, serial, and validation tests in `tests/Feature/Livewire/PosReturn/PosReturnCreateFormTest.php`

### Implementation for User Story 2

- [ ] T041 [US2] Implement snapshot hash revalidation and submitted line normalization in `app/Support/PosReturn/PosReturnSubmissionService.php`
- [ ] T042 [US2] Implement cash return and product replacement option validation in `app/Support/PosReturn/PosReturnSubmissionService.php`
- [ ] T043 [US2] Implement positive quantity, serial selection, and dispatch-detail cap checks in `app/Support/PosReturn/PosReturnQuantityGuard.php`
- [ ] T044 [US2] Implement active cumulative return aggregation excluding rejected or archived returns in `app/Support/PosReturn/PosReturnQuantityGuard.php`
- [ ] T045 [US2] Implement bundle component expansion and proportional bundle quantity validation in `app/Support/PosReturn/PosReturnSubmissionService.php`
- [ ] T046 [US2] Implement POS Return header and line persistence with audit actors in `app/Support/PosReturn/PosReturnSubmissionService.php`
- [ ] T047 [US2] Implement owner/sale-grouped Sales Return and Sale Return Detail creation in `app/Support/PosReturn/PosReturnSubmissionService.php`
- [ ] T048 [US2] Implement `store()` endpoint, validation redirects, and user-facing errors in `Modules/Pos/Http/Controllers/PosReturnController.php`
- [ ] T049 [US2] Add return option controls, editable quantities, serial selection, bundle group display, and submit action in `resources/views/livewire/pos-return/pos-return-create-form.blade.php`
- [ ] T050 [US2] Add submit, reset, and table-error handling to the Livewire create form in `app/Livewire/PosReturn/PosReturnCreateForm.php`
- [ ] T051 [P] [US2] Implement pre-approval edit page shell in `Modules/Pos/Resources/views/returns/edit.blade.php`
- [ ] T052 [US2] Implement edit, update, and delete guards before approval in `Modules/Pos/Http/Controllers/PosReturnController.php`

**Checkpoint**: User Story 2 can submit valid POS returns and reject invalid submissions independently.

---

## Phase 5: User Story 3 - Approve, Receive, and Dispatch POS Returns (Priority: P3)

**Goal**: Authorized users can approve or reject submitted POS returns, archive/cancel approved returns before receiving through an audited reversal path, receive returned goods, process manual cash refunds, and dispatch replacements only through permitted lifecycle paths.

**Independent Test**: Submit a POS return, approve or reject it, archive/cancel an approved return before receiving with audit details, block archive/cancel after receiving/settlement/dispatch, receive approved returns, process cash refunds only for cash returns, and dispatch replacements only for product replacement returns.

### Tests for User Story 3

- [ ] T053 [P] [US3] Add approve and reject lifecycle tests with actor, timestamp, and rejection reason assertions in `Modules/Pos/Tests/Feature/POSReturnApprovalWorkflowTest.php`
- [ ] T054 [P] [US3] Add receive-after-approval and receive-before-approval block tests in `Modules/Pos/Tests/Feature/POSReturnReceivingWorkflowTest.php`
- [ ] T055 [P] [US3] Add cash refund allowed/blocked option tests in `Modules/Pos/Tests/Feature/POSReturnCashRefundWorkflowTest.php`
- [ ] T056 [P] [US3] Add replacement dispatch allowed/blocked option tests in `Modules/Pos/Tests/Feature/POSReturnReplacementDispatchWorkflowTest.php`
- [ ] T057 [P] [US3] Add post-approval edit/delete block tests in `Modules/Pos/Tests/Feature/POSReturnLifecycleGuardTest.php`
- [ ] T085 [P] [US3] Add audited archive/cancel workflow tests for approved-before-receiving returns and blocked received/settled/dispatched returns in `Modules/Pos/Tests/Feature/POSReturnArchiveCancelWorkflowTest.php`

### Implementation for User Story 3

- [ ] T058 [US3] Implement approve and reject wrapper behavior with linked Sales Return status updates in `app/Support/PosReturn/PosReturnLifecycleService.php`
- [ ] T059 [US3] Implement receive behavior by delegating to linked Sales Return receiving logic and syncing POS wrapper status in `app/Support/PosReturn/PosReturnLifecycleService.php`
- [ ] T060 [US3] Implement manual cash refund settlement guard and cap logic for cash returns in `app/Support/PosReturn/PosReturnLifecycleService.php`
- [ ] T061 [US3] Implement replacement dispatch guard for product replacement returns in `app/Support/PosReturn/PosReturnLifecycleService.php`
- [ ] T086 [US3] Implement audited archive/cancel behavior with actor, timestamp, reason, linked Sales Return state handling, and inventory/financial mutation guards in `app/Support/PosReturn/PosReturnLifecycleService.php`
- [ ] T062 [US3] Implement approve, reject, receive, cash-refund, and dispatch controller actions in `Modules/Pos/Http/Controllers/PosReturnController.php`
- [ ] T087 [US3] Implement archive/cancel controller action, permission gate, reason validation, redirect flow, and user-facing lifecycle errors in `Modules/Pos/Http/Controllers/PosReturnController.php`
- [ ] T063 [US3] Render status header, action buttons, rejection form, receiving action, settlement action, and dispatch action in `Modules/Pos/Resources/views/returns/show.blade.php`
- [ ] T088 [US3] Render archive/cancel action UI only for eligible approved-before-receiving POS returns in `Modules/Pos/Resources/views/returns/show.blade.php`
- [ ] T064 [P] [US3] Add reusable linked Sales Return and POS Return status partials in `Modules/Pos/Resources/views/returns/partials/status.blade.php`
- [ ] T065 [US3] Ensure Sales Return lifecycle sync calls update POS wrapper completion status in `app/Support/SalesReturn/SaleReturnLifecycleSyncService.php`
- [ ] T066 [US3] Enforce POS-specific lifecycle permissions for linked Sales Return actions in `Modules/SalesReturn/Http/Controllers/SalesReturnController.php`

**Checkpoint**: User Story 3 can advance POS returns through approval, receiving, settlement, and replacement dispatch guards.

---

## Phase 6: User Story 4 - Reverse Split POS Sales by Original Ownership (Priority: P4)

**Goal**: Returned quantities reverse the correct generated sale, dispatch detail, owner, location, and tax context for split-owner POS transactions.

**Independent Test**: Use a POS checkout that generated multiple owner-aligned sales, return items from each group, and verify every line maps to the original sale, dispatch detail, owner, location, tax context, and quantity cap.

### Tests for User Story 4

- [ ] T067 [P] [US4] Add split-owner POS return mapping tests in `Modules/Pos/Tests/Feature/POSReturnSplitOwnerMappingTest.php`
- [ ] T068 [P] [US4] Add dispatch quantity reduction tests after receiving split-owner returns in `Modules/Pos/Tests/Feature/POSReturnDispatchQuantityAdjustmentTest.php`
- [ ] T069 [P] [US4] Add replacement source owner/location constraint tests in `Modules/Pos/Tests/Feature/POSReturnReplacementSourceConstraintTest.php`
- [ ] T070 [P] [US4] Add serial-tracked split-owner return tests in `Modules/Pos/Tests/Feature/POSReturnSerialSplitOwnerTest.php`

### Implementation for User Story 4

- [ ] T071 [US4] Preserve source setting, source location, tax, sale, sale detail, dispatch detail, and checkout sale IDs on every line in `app/Support/PosReturn/PosReturnSubmissionService.php`
- [ ] T072 [US4] Group linked Sales Return creation by original sale and owner/location context in `app/Support/PosReturn/PosReturnSubmissionService.php`
- [ ] T073 [US4] Apply dispatch-detail quantity reduction only after receiving linked Sales Returns in `app/Support/PosReturn/PosReturnLifecycleService.php`
- [ ] T074 [US4] Enforce replacement source setting/location constraints before dispatch request or dispatch approval in `app/Support/PosReturn/PosReturnLifecycleService.php`
- [ ] T075 [US4] Preserve serial-level return identity from original dispatch details in `app/Support/PosReturn/PosReturnSubmissionService.php`
- [ ] T076 [US4] Show owner, sale reference, dispatch detail, location, tax, and serial context on POS Return detail rows in `Modules/Pos/Resources/views/returns/show.blade.php`

**Checkpoint**: User Story 4 proves split-owner POS reversals stay aligned with the original generated sales and dispatches.

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: Finish auditability, documentation, performance, and full verification across the feature.

- [ ] T077 [P] Add POS return audit assertions for create, edit/delete/archive, approve, reject, receive, settlement, and dispatch in `Modules/Pos/Tests/Feature/POSReturnAuditTrailTest.php`
- [ ] T078 [P] Add query count or indexed lookup regression coverage for transaction/receipt lookup in `Modules/Pos/Tests/Feature/POSReturnLookupPerformanceTest.php`
- [ ] T079 [P] Add POS return quickstart notes and known residual risks to `specs/20260501-224617-pos-return-by-trx-number/quickstart.md`
- [ ] T080 Run focused POS Return verification and record any required fixes in `specs/20260501-224617-pos-return-by-trx-number/tasks.md`
- [ ] T081 Run Sales Return regression verification for linked lifecycle behavior and record any required fixes in `specs/20260501-224617-pos-return-by-trx-number/tasks.md`
- [ ] T082 Run final permission sync smoke check for POS return permissions in `app/Config/Permissions.php`
- [ ] T089 Run `php artisan migrate` against a MySQL/MariaDB-backed environment for the POS return migrations and record migration compatibility or rollback issues in `specs/20260501-224617-pos-return-by-trx-number/quickstart.md`

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 Setup**: No dependencies; can start immediately.
- **Phase 2 Foundational**: Depends on Phase 1; blocks all user story phases.
- **Phase 3 US1**: Depends on Phase 2; delivers transaction lookup and snapshot MVP.
- **Phase 4 US2**: Depends on Phase 2 and uses US1 snapshot behavior for the normal UI path.
- **Phase 5 US3**: Depends on submitted returns from US2.
- **Phase 6 US4**: Depends on US2 submission and US3 receiving/dispatch behavior, but tests can be drafted earlier.
- **Phase 7 Polish**: Depends on the desired story phases being complete.

### User Story Dependencies

- **US1 (P1)**: Starts after Phase 2 and has no dependency on other stories.
- **US2 (P2)**: Starts after Phase 2; uses US1 lookup data in UI but service-level submission can be tested from rebuilt snapshots.
- **US3 (P3)**: Starts after US2 creates submitted POS returns.
- **US4 (P4)**: Starts after US2 for submission mapping and after US3 for received quantity reduction and dispatch source enforcement.

### Within Each User Story

- Write tests first and confirm they fail before implementation.
- Implement models/schema before services.
- Implement services before controller endpoints and Livewire integration.
- Implement UI after service and controller behavior exists.
- Validate each story at its checkpoint before moving to the next priority.

---

## Parallel Opportunities

- Setup tasks T001-T004 can run in parallel.
- Foundational model, permission, and migration tasks T005-T014 and T083-T084 can run in parallel when file ownership is separated.
- Test tasks within each user story can run in parallel before implementation.
- US1 UI page shell tasks T030, T033, and T034 can run in parallel after routes are known.
- US2 tests T035-T040 can run in parallel; implementation tasks T041-T048 should be completed before UI tasks T049-T052.
- US3 tests T053-T057 and T085 can run in parallel; lifecycle service tasks T058-T061 and T086 should precede controller and view tasks T062-T064 and T087-T088.
- US4 tests T067-T070 can run in parallel with US4 service hardening tasks T071-T075 after US2 baseline submission exists.

---

## Parallel Example: User Story 1

```bash
Task: "T021 [P] [US1] Add route authorization tests for /pos/returns, /pos/returns/create, and /pos/returns/lookup in Modules/Pos/Tests/Feature/POSReturnRouteAuthorizationTest.php"
Task: "T022 [P] [US1] Add successful transaction-code and receipt-number lookup tests in Modules/Pos/Tests/Feature/POSReturnLookupTest.php"
Task: "T024 [P] [US1] Add Livewire snapshot rendering tests for header, owner groups, payments, dispatch status, returnable lines, and hash in tests/Feature/Livewire/PosReturn/PosReturnCreateFormTest.php"
```

## Parallel Example: User Story 2

```bash
Task: "T035 [P] [US2] Add cash return submission tests for non-bundle POS lines in Modules/Pos/Tests/Feature/POSReturnSubmissionTest.php"
Task: "T038 [P] [US2] Add bundle return expansion and parent-only rejection tests in Modules/Pos/Tests/Feature/POSReturnBundleSubmissionTest.php"
Task: "T039 [P] [US2] Add cumulative dispatch quantity cap tests for partial and duplicate returns in Modules/Pos/Tests/Feature/POSReturnQuantityGuardTest.php"
```

## Parallel Example: User Story 3

```bash
Task: "T053 [P] [US3] Add approve and reject lifecycle tests with actor, timestamp, and rejection reason assertions in Modules/Pos/Tests/Feature/POSReturnApprovalWorkflowTest.php"
Task: "T055 [P] [US3] Add cash refund allowed/blocked option tests in Modules/Pos/Tests/Feature/POSReturnCashRefundWorkflowTest.php"
Task: "T056 [P] [US3] Add replacement dispatch allowed/blocked option tests in Modules/Pos/Tests/Feature/POSReturnReplacementDispatchWorkflowTest.php"
```

## Parallel Example: User Story 4

```bash
Task: "T067 [P] [US4] Add split-owner POS return mapping tests in Modules/Pos/Tests/Feature/POSReturnSplitOwnerMappingTest.php"
Task: "T069 [P] [US4] Add replacement source owner/location constraint tests in Modules/Pos/Tests/Feature/POSReturnReplacementSourceConstraintTest.php"
Task: "T070 [P] [US4] Add serial-tracked split-owner return tests in Modules/Pos/Tests/Feature/POSReturnSerialSplitOwnerTest.php"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1 setup.
2. Complete Phase 2 foundational schema, model, route, and service entry points.
3. Complete Phase 3 lookup and snapshot behavior.
4. Validate with `php artisan test --filter=POSReturnLookup` and `php artisan test --filter=PosReturnCreateForm`.
5. Demo `/pos/returns/create` lookup for valid and invalid transaction numbers.

### Incremental Delivery

1. Deliver US1 lookup and immutable snapshot as the MVP.
2. Add US2 submission for cash return and product replacement.
3. Add US3 approval, receiving, cash settlement, and replacement dispatch lifecycle controls.
4. Add US4 split-owner, owner/location, dispatch quantity, and serial hardening.
5. Run quickstart validation after each story checkpoint.

### Parallel Team Strategy

1. One developer owns migrations/entities and shared service scaffolding in Phase 2.
2. One developer owns POS permissions, role matrix, routes, and menu integration in Phase 1/Phase 2.
3. After Phase 2, separate developers can draft US1, US2, US3, and US4 tests in parallel.
4. Implementation should merge in priority order to keep the MVP demonstrable.

---

## Independent Test Criteria

- **US1**: Valid completed POS transaction code or receipt number populates an immutable snapshot; unknown, unposted, cancelled, out-of-scope, and fully returned transactions are blocked.
- **US2**: Cash and product replacement returns persist correct POS Return lines and linked Sales Return records; bundle returns expand all components; stale snapshots and over-quantity submissions are blocked.
- **US3**: Approval gates receiving; approved-before-receiving archive/cancel is audited; receiving gates cash settlement and replacement dispatch; option-specific lifecycle actions are mutually exclusive and audited.
- **US4**: Split-owner returns preserve original sale, dispatch detail, owner, location, tax, and serial identity; received returns reduce only the matching dispatch detail quantity.

## Notes

- Suggested MVP scope is Phase 1, Phase 2, and Phase 3 (US1).
- Use `php artisan test --filter=POSReturn`, `php artisan test --filter=PosReturn`, and `composer test:fresh-sqlite -- --filter=POSReturn` for verification.
- Create and apply production migrations through Laravel migration tooling against MySQL/MariaDB; SQLite is only for focused unit/integration test execution.
- Keep POS-specific entry points in `Modules/Pos` and reuse existing Sales Return lifecycle behavior instead of creating a separate return engine.
