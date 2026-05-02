# Tasks: POS Return by Transaction Number

**Input**: Design documents from `/specs/20260501-224617-pos-return-by-trx-number/`
**Prerequisites**: `plan.md`, `spec.md`, `research.md`, `data-model.md`, `contracts/pos-return-contract.md`, `quickstart.md`
**Tests**: Included because the feature specification, research, quickstart, and constitution gate require focused automated verification for lookup, permissions, split-owner mapping, bundle handling, lifecycle guards, atomic rollback, and quantity limits.
**Organization**: Tasks are grouped by user story so each story can be implemented and tested independently after the foundational phase.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel because it touches different files or depends only on completed prerequisites
- **[Story]**: User story label for story-phase tasks only
- Every task includes an exact target file path, or an exact target directory path when the task creates directory placeholders

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Prepare shared POS return directories, permissions, and operator navigation.

- [x] T001 [P] Create POS return directory placeholders in `Modules/Pos/Services/`, `Modules/Pos/Resources/views/returns/`, `app/Livewire/PosReturn/`, `resources/views/livewire/pos-return/`, and `app/Support/PosReturn/`
- [x] T002 [P] Register `pos.returns.view`, `pos.returns.create`, `pos.returns.edit`, `pos.returns.delete`, `pos.returns.approve`, `pos.returns.receive`, `pos.returns.settle`, and `pos.returns.dispatch` permission labels in `app/Config/Permissions.php`
- [x] T003 [P] Add POS return permission groups and role matrix mappings in `Modules/Pos/Support/PosPermissionMatrix.php`
- [x] T004 [P] Add POS return menu entry visibility and active-state rules in `resources/views/layouts/menu.blade.php`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Add schema, entities, shared services, and route contracts required before any user story can be implemented.

**Critical**: No user story implementation should begin until this phase is complete.

- [x] T005 Create `pos_returns` table migration with transaction, checkout, snapshot, lifecycle, option, total, audit, status, and lookup indexes in `Modules/Pos/Database/Migrations/2026_05_01_000001_create_pos_returns_table.php`
- [x] T006 Create `pos_return_lines` table migration with sale, dispatch, owner, location, tax, serial, bundle, stock behavior, quantity, money, and linkage indexes in `Modules/Pos/Database/Migrations/2026_05_01_000002_create_pos_return_lines_table.php`
- [x] T007 Create nullable Sales Return POS linkage migration with `pos_return_id`, POS source metadata, compatible indexes, and dependency-safe rollback in `Modules/Pos/Database/Migrations/2026_05_01_000003_add_pos_return_link_columns_to_sale_returns_table.php`
- [x] T008 Create nullable Sale Return Detail POS linkage migration with `pos_return_line_id`, `bundle_group_key`, `stock_behavior`, compatible indexes, and dependency-safe rollback in `Modules/Pos/Database/Migrations/2026_05_01_000004_add_pos_return_line_link_columns_to_sale_return_details_table.php`
- [x] T009 [P] Implement `PosReturn` model constants, casts, fillable fields, scopes, and relationships in `Modules/Pos/Entities/PosReturn.php`
- [x] T010 [P] Implement `PosReturnLine` model constants, casts, fillable fields, and relationships in `Modules/Pos/Entities/PosReturnLine.php`
- [x] T011 Extend `SaleReturn` with POS return relationships, fillable fields, casts, and source metadata accessors in `Modules/SalesReturn/Entities/SaleReturn.php`
- [x] T012 Extend `SaleReturnDetail` with POS return line relationship, bundle group metadata, and stock behavior casts in `Modules/SalesReturn/Entities/SaleReturnDetail.php`
- [x] T013 [P] Add migration and relationship tests for POS return tables and Sales Return links in `Modules/Pos/Tests/Feature/POSReturnModelRelationshipTest.php`
- [x] T014 [P] Add permission registration and POS role matrix tests in `Modules/Pos/Tests/Feature/POSReturnPermissionMatrixTest.php`
- [x] T015 Implement active cumulative return quantity guard skeleton in `app/Support/PosReturn/PosReturnQuantityGuard.php`
- [x] T016 Implement POS return lookup service skeleton and DTO shape in `Modules/Pos/Services/PosReturnLookupService.php`
- [x] T017 Implement source snapshot builder skeleton with canonical hash contract in `Modules/Pos/Services/PosReturnSnapshotService.php`
- [x] T018 Implement submission service skeleton for validation, line normalization, Sales Return creation, and persistence in `Modules/Pos/Services/PosReturnSubmissionService.php`
- [x] T019 Implement lifecycle service skeleton for approve, reject, receive, payment return settlement, replacement dispatch, archive/cancel, and status sync in `Modules/Pos/Services/PosReturnLifecycleService.php`
- [x] T020 Register POS return routes from `contracts/pos-return-contract.md` in `Modules/Pos/Routes/web.php`
- [x] T021 Implement controller action shells with per-action permission gates and service injection in `Modules/Pos/Http/Controllers/PosReturnController.php`

**Checkpoint**: Database, entities, permissions, routes, and service entry points exist for story implementation.

---

## Phase 3: User Story 1 - Start POS Return from Transaction Number (Priority: P1) MVP

**Goal**: A permitted user can enter a completed POS transaction code or receipt number and view an immutable source snapshot for the exact POS sale.

**Independent Test**: Enter valid, unknown, unposted, cancelled, out-of-scope, ambiguous, and fully returned POS transaction numbers; verify only valid posted transactions populate snapshot data with generated sales, owner groups, dispatch state, returnable lines, payment summary, and snapshot hash.

### Tests for User Story 1

- [x] T022 [P] [US1] Add route authorization tests for `/pos/returns`, `/pos/returns/create`, and `/pos/returns/lookup` in `Modules/Pos/Tests/Feature/POSReturnRouteAuthorizationTest.php`
- [x] T023 [P] [US1] Add successful transaction-code and receipt-number lookup tests in `Modules/Pos/Tests/Feature/POSReturnLookupTest.php`
- [x] T024 [P] [US1] Add blocked lookup tests for unknown, non-posted, cancelled, cross-setting, ambiguous, and fully returned transactions in `Modules/Pos/Tests/Feature/POSReturnLookupTest.php`
- [x] T025 [P] [US1] Add Livewire snapshot rendering tests for header, owner groups, payments, dispatch status, returnable lines, and hash in `tests/Feature/Livewire/PosReturn/PosReturnCreateFormTest.php`

### Implementation for User Story 1

- [x] T026 [US1] Implement completed POS transaction and receipt lookup with exact-one-match and setting visibility checks in `Modules/Pos/Services/PosReturnLookupService.php`
- [x] T027 [US1] Build snapshot transaction header, customer, checkout, cashier/session, payment summary, and owner groups in `Modules/Pos/Services/PosReturnSnapshotService.php`
- [x] T028 [US1] Build returnable line snapshot from generated sales, sale details, dispatch details, serials, bundles, and active return quantities in `Modules/Pos/Services/PosReturnSnapshotService.php`
- [x] T029 [US1] Add stable canonical source snapshot hashing and stale metadata generation in `Modules/Pos/Services/PosReturnSnapshotService.php`
- [x] T030 [US1] Implement `lookup()` response handling and user-facing failure messages in `Modules/Pos/Http/Controllers/PosReturnController.php`
- [x] T031 [P] [US1] Implement POS return list Livewire component for index display in `app/Livewire/PosReturn/PosReturnTable.php`
- [x] T032 [US1] Implement transaction number lookup state, validation, and snapshot assignment in `app/Livewire/PosReturn/PosReturnCreateForm.php`
- [x] T033 [US1] Render lookup form, immutable source snapshot, owner groups, payment summary, and returnable lines in `resources/views/livewire/pos-return/pos-return-create-form.blade.php`
- [x] T034 [P] [US1] Create POS return index page using the Livewire table in `Modules/Pos/Resources/views/returns/index.blade.php`
- [x] T035 [P] [US1] Create POS return create page using the Livewire create form in `Modules/Pos/Resources/views/returns/create.blade.php`

**Checkpoint**: User Story 1 is functional and testable without submitting a return.

---

## Phase 4: User Story 2 - Submit Return for Payment Return or Product Replacement (Priority: P2)

**Goal**: A permitted user can select return quantities, choose payment return or product replacement, and submit POS Return records with owner/sale-aligned Sales Return records or lines.

**Independent Test**: Create payment-return and replacement returns for normal and bundle POS lines; verify persisted POS Return data, linked Sales Return data, bundle component expansion, stale snapshot blocking, serial validation, and quantity caps.

### Tests for User Story 2

- [x] T036 [P] [US2] Add payment return submission tests for non-bundle POS lines in `Modules/Pos/Tests/Feature/POSReturnSubmissionTest.php`
- [x] T037 [P] [US2] Add product replacement submission tests for non-bundle POS lines in `Modules/Pos/Tests/Feature/POSReturnSubmissionTest.php`
- [x] T038 [P] [US2] Add stale snapshot hash rejection tests in `Modules/Pos/Tests/Feature/POSReturnSnapshotStalenessTest.php`
- [x] T039 [P] [US2] Add bundle expansion, proportional quantity, stockless component, and parent-only rejection tests in `Modules/Pos/Tests/Feature/POSReturnBundleSubmissionTest.php`
- [x] T040 [P] [US2] Add cumulative dispatch quantity cap tests for partial, duplicate, rejected, deleted, archived, and completed returns in `Modules/Pos/Tests/Feature/POSReturnQuantityGuardTest.php`
- [x] T041 [P] [US2] Add Livewire quantity, option, serial, bundle, and validation tests in `tests/Feature/Livewire/PosReturn/PosReturnCreateFormTest.php`
- [x] T042 [P] [US2] Add permission revocation test between snapshot lookup and submission in `Modules/Pos/Tests/Feature/POSReturnRouteAuthorizationTest.php`

### Implementation for User Story 2

- [x] T043 [US2] Implement server-rebuilt snapshot hash revalidation before store and update in `Modules/Pos/Services/PosReturnSubmissionService.php`
- [x] T044 [US2] Implement payment return and product replacement option validation in `Modules/Pos/Services/PosReturnSubmissionService.php`
- [x] T045 [US2] Implement positive quantity, serial selection, and dispatch-detail cap checks in `app/Support/PosReturn/PosReturnQuantityGuard.php`
- [x] T046 [US2] Implement active non-reversed cumulative return aggregation and eligibility release rules in `app/Support/PosReturn/PosReturnQuantityGuard.php`
- [x] T047 [US2] Implement bundle component expansion, proportional bundle quantity validation, and stockless audit-line handling in `Modules/Pos/Services/PosReturnSubmissionService.php`
- [x] T048 [US2] Persist POS Return header, POS Return lines, source snapshot, option, total, status, and audit actors in `Modules/Pos/Services/PosReturnSubmissionService.php`
- [x] T049 [US2] Create owner/sale-grouped linked Sales Return and Sale Return Detail records from submitted POS Return lines in `Modules/Pos/Services/PosReturnSubmissionService.php`
- [x] T050 [US2] Wrap submit and update behavior in atomic database transactions with row locking in `Modules/Pos/Services/PosReturnSubmissionService.php`
- [x] T051 [US2] Implement `store()`, `edit()`, `update()`, and `destroy()` endpoint behavior with clear validation errors in `Modules/Pos/Http/Controllers/PosReturnController.php`
- [x] T052 [US2] Add return option controls, editable quantities, serial selection, bundle group display, and submit action in `resources/views/livewire/pos-return/pos-return-create-form.blade.php`
- [x] T053 [US2] Add submit, reset, stale snapshot, and table error handling to the Livewire create form in `app/Livewire/PosReturn/PosReturnCreateForm.php`
- [x] T054 [P] [US2] Implement pre-approval edit page shell in `Modules/Pos/Resources/views/returns/edit.blade.php`

**Checkpoint**: User Story 2 can submit valid POS returns and reject invalid submissions independently.

---

## Phase 5: User Story 3 - Approve, Receive, and Dispatch POS Returns (Priority: P3)

**Goal**: Authorized users can approve or reject submitted POS returns, receive returned goods, process payment return settlements, dispatch same-SKU replacements, and use audited archive/cancel controls only where lifecycle rules allow them.

**Independent Test**: Submit a POS return, approve or reject it, archive/cancel an approved return before receiving, block archive/cancel after receiving, receive approved returns, process payment return settlements only for payment returns, and dispatch replacements only for product replacement returns from the original owner/location.

### Tests for User Story 3

- [x] T055 [P] [US3] Add approve and reject lifecycle tests with actor, timestamp, and rejection reason assertions in `Modules/Pos/Tests/Feature/POSReturnApprovalWorkflowTest.php`
- [x] T056 [P] [US3] Add receive-after-approval and receive-before-approval block tests in `Modules/Pos/Tests/Feature/POSReturnReceivingWorkflowTest.php`
- [x] T057 [P] [US3] Add payment return settlement allowed/blocked option and cap tests in `Modules/Pos/Tests/Feature/POSReturnPaymentReturnWorkflowTest.php`
- [x] T058 [P] [US3] Add replacement dispatch allowed/blocked option, same-SKU, same-quantity, and stock-availability tests in `Modules/Pos/Tests/Feature/POSReturnReplacementDispatchWorkflowTest.php`
- [x] T059 [P] [US3] Add post-approval and post-receiving edit/delete/reject block tests in `Modules/Pos/Tests/Feature/POSReturnLifecycleGuardTest.php`
- [x] T060 [P] [US3] Add permission revocation tests before approve, receive, payment-return-settlement via `pos.returns.settle`, and replacement-dispatch actions in `Modules/Pos/Tests/Feature/POSReturnLifecycleGuardTest.php`
- [x] T061 [P] [US3] Add audited archive/cancel workflow tests for approved-before-receiving returns and blocked received/settled/dispatched returns in `Modules/Pos/Tests/Feature/POSReturnArchiveCancelWorkflowTest.php`
- [ ] T062 [P] [US3] Add atomic rollback tests for approve, reject, receive, payment return settlement, replacement dispatch, and archive/cancel failures in `Modules/Pos/Tests/Feature/POSReturnAtomicLifecycleTest.php`

### Implementation for User Story 3

- [x] T063 [US3] Implement approve and reject wrapper behavior with actor audit and linked Sales Return status updates in `Modules/Pos/Services/PosReturnLifecycleService.php`
- [x] T064 [US3] Implement receive behavior by delegating to linked Sales Return receiving logic and syncing POS wrapper status in `Modules/Pos/Services/PosReturnLifecycleService.php`
- [x] T065 [US3] Implement payment return settlement guard, owner/sale allocation, and returned amount cap for payment returns in `Modules/Pos/Services/PosReturnLifecycleService.php`
- [x] T066 [US3] Implement replacement dispatch guard for product replacement returns, same-SKU rule, same-quantity rule, and original owner/location source checks in `Modules/Pos/Services/PosReturnLifecycleService.php`
- [x] T067 [US3] Implement audited archive/cancel behavior with actor, timestamp, reason, linked Sales Return state handling, and inventory/financial mutation guards in `Modules/Pos/Services/PosReturnLifecycleService.php`
- [x] T068 [US3] Wrap approve, reject, receive, payment-return-settlement, replacement-dispatch, and archive/cancel actions in atomic database transactions in `Modules/Pos/Services/PosReturnLifecycleService.php`
- [ ] T069 [US3] Implement non-rollbackable external-effect failure handling that blocks further lifecycle progress and records required audited manual correction state in `Modules/Pos/Services/PosReturnLifecycleService.php`
- [ ] T070 [P] [US3] Add manual correction blocking tests for non-rollbackable lifecycle failures in `Modules/Pos/Tests/Feature/POSReturnManualCorrectionWorkflowTest.php`
- [x] T071 [US3] Implement approve, reject, receive, payment-return-settlement, dispatch, archive, and cancel controller actions with permission gates and lifecycle errors in `Modules/Pos/Http/Controllers/PosReturnController.php`
- [x] T072 [US3] Render POS Return status header, linked Sales Return summary, approval controls, receiving control, payment return settlement control, dispatch control, and archive/cancel control in `Modules/Pos/Resources/views/returns/show.blade.php`
- [x] T073 [P] [US3] Add reusable POS Return and linked Sales Return status partial in `Modules/Pos/Resources/views/returns/partials/status.blade.php`
- [x] T074 [US3] Ensure linked Sales Return lifecycle sync updates POS wrapper completion status in `app/Support/SalesReturn/SaleReturnLifecycleSyncService.php`
- [x] T075 [US3] Enforce POS-specific lifecycle permission checks for linked POS Return actions in `Modules/SalesReturn/Http/Controllers/SalesReturnController.php`

**Checkpoint**: User Story 3 can advance POS returns through approval, receiving, payment return settlement, replacement dispatch, audited cancellation guards, and manual-correction blocking for non-rollbackable failures.

---

## Phase 6: User Story 4 - Reverse Split POS Sales by Original Ownership (Priority: P4)

**Goal**: Returned quantities reverse the correct generated sale, dispatch detail, owner, location, serial, and tax context for split-owner POS transactions.

**Independent Test**: Use a POS checkout that generated multiple owner-aligned sales, return items from each group, and verify every line maps to the original sale, dispatch detail, owner, location, tax context, serial identity, and quantity cap.

### Tests for User Story 4

- [ ] T076 [P] [US4] Add split-owner POS return mapping tests in `Modules/Pos/Tests/Feature/POSReturnSplitOwnerMappingTest.php`
- [ ] T077 [P] [US4] Add dispatch quantity reduction tests after receiving split-owner returns in `Modules/Pos/Tests/Feature/POSReturnDispatchQuantityAdjustmentTest.php`
- [ ] T078 [P] [US4] Add replacement source owner/location constraint tests in `Modules/Pos/Tests/Feature/POSReturnReplacementSourceConstraintTest.php`
- [ ] T079 [P] [US4] Add serial-tracked split-owner return tests in `Modules/Pos/Tests/Feature/POSReturnSerialSplitOwnerTest.php`

### Implementation for User Story 4

- [ ] T080 [US4] Preserve source setting, source location, tax, sale, sale detail, dispatch detail, checkout sale, and serial IDs on every line in `Modules/Pos/Services/PosReturnSubmissionService.php`
- [ ] T081 [US4] Group linked Sales Return creation by original sale and owner/location context in `Modules/Pos/Services/PosReturnSubmissionService.php`
- [ ] T082 [US4] Apply dispatch-detail quantity reduction only after receiving linked Sales Returns in `Modules/Pos/Services/PosReturnLifecycleService.php`
- [ ] T083 [US4] Enforce replacement source setting/location constraints before dispatch request or dispatch approval in `Modules/Pos/Services/PosReturnLifecycleService.php`
- [ ] T084 [US4] Preserve serial-level return identity from original dispatch details through linked Sales Return details in `Modules/Pos/Services/PosReturnSubmissionService.php`
- [ ] T085 [US4] Show owner, sale reference, dispatch detail, location, tax, and serial context on POS Return detail rows in `Modules/Pos/Resources/views/returns/show.blade.php`

**Checkpoint**: User Story 4 proves split-owner POS reversals stay aligned with the original generated sales and dispatches.

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: Finish auditability, performance coverage, documentation, and full verification across the feature.

- [ ] T086 [P] Add POS return audit assertions for create, edit/delete/archive, approve, reject, receive, payment return settlement, and dispatch in `Modules/Pos/Tests/Feature/POSReturnAuditTrailTest.php`
- [ ] T087 [P] Add query count or indexed lookup regression coverage for transaction/receipt lookup in `Modules/Pos/Tests/Feature/POSReturnLookupPerformanceTest.php`
- [ ] T088 [P] Add POS return quickstart notes, UAT measurement instructions, and residual risk updates in `specs/20260501-224617-pos-return-by-trx-number/quickstart.md`
- [ ] T089 Run SC-001 and SC-002 POS transaction-number lookup UAT with valid posted, invalid, unposted, unauthorized, fully returned, and ambiguous transaction numbers, then record pass/fail notes in `specs/20260501-224617-pos-return-by-trx-number/quickstart.md`
- [ ] T090 Review POS Return list, create/edit, and detail screens against existing Sales Return UI conventions for table structure, status badge placement, primary actions, approval/receiving/payment-return-settlement action grouping, validation placement, and Bootstrap/CoreUI layout, then record findings in `specs/20260501-224617-pos-return-by-trx-number/quickstart.md`
- [ ] T091 Run the SC-003 20-return UAT matrix with at least 4 normal non-bundle returns, 4 bundled returns, 4 split-owner returns, 3 partial returns, 2 serial-tracked returns, and at least 5 returns for each option (`payment_return` and `product_replacement`), then record pass/fail mapping notes in `specs/20260501-224617-pos-return-by-trx-number/quickstart.md`
- [ ] T092 Run the SC-006 timed intake UAT scenario in staging with production-like data, a trained authorized user, and a standard 25-line receipt, then record lookup-to-submit duration and pass/fail notes in `specs/20260501-224617-pos-return-by-trx-number/quickstart.md`
- [ ] T093 Run focused POS Return verification and record pass/fail notes in `specs/20260501-224617-pos-return-by-trx-number/tasks.md`
- [ ] T094 Run linked Sales Return regression verification and record pass/fail notes in `specs/20260501-224617-pos-return-by-trx-number/tasks.md`
- [ ] T095 Run permission sync smoke check and record any fixes in `app/Config/Permissions.php`
- [ ] T096 Run MySQL/MariaDB migration and rollback verification for POS return migrations and record compatibility notes in `specs/20260501-224617-pos-return-by-trx-number/quickstart.md`

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 Setup**: No dependencies; can start immediately.
- **Phase 2 Foundational**: Depends on Phase 1 and blocks all user story phases.
- **Phase 3 US1**: Depends on Phase 2 and delivers the transaction lookup and snapshot MVP.
- **Phase 4 US2**: Depends on Phase 2 and uses US1 snapshot behavior for the normal UI path.
- **Phase 5 US3**: Depends on US2 because lifecycle actions require submitted POS returns.
- **Phase 6 US4**: Depends on US2 for submission mapping and US3 for received quantity reduction and dispatch source enforcement.
- **Phase 7 Polish**: Depends on the desired story phases being complete.

### User Story Dependencies

- **US1 (P1)**: Starts after Phase 2 and has no dependency on other stories.
- **US2 (P2)**: Starts after Phase 2; service-level tests can build snapshots directly, while the UI path uses US1 lookup.
- **US3 (P3)**: Starts after US2 creates submitted POS returns.
- **US4 (P4)**: Starts after US2 for owner/sale mapping and after US3 for receiving and dispatch enforcement.

### Within Each User Story

- Write tests first and confirm they fail before implementation.
- Implement schema and models before services.
- Implement services before controller endpoints and Livewire integration.
- Implement UI after service and controller behavior exists.
- Validate each story at its checkpoint before moving to the next priority.

---

## Parallel Opportunities

- Setup tasks T001-T004 can run in parallel.
- Foundational tasks marked `[P]` can run in parallel when file ownership is separated; unmarked tasks T005-T008 and T011-T012, T015-T021 should follow their listed dependencies and ownership constraints.
- Tests within each user story can run in parallel before implementation.
- US1 UI page tasks T031, T034, and T035 can run in parallel after routes are known.
- US2 tests T036-T042 can run in parallel; implementation tasks T043-T051 should precede UI tasks T052-T054.
- US3 tests T055-T062 and T070 can run in parallel; lifecycle service tasks T063-T069 should precede controller and view tasks T071-T075.
- US4 tests T076-T079 can run in parallel with US4 service hardening tasks T080-T084 after US2 baseline submission exists.

---

## Parallel Example: User Story 1

```bash
Task: "T022 [P] [US1] Add route authorization tests for /pos/returns, /pos/returns/create, and /pos/returns/lookup in Modules/Pos/Tests/Feature/POSReturnRouteAuthorizationTest.php"
Task: "T023 [P] [US1] Add successful transaction-code and receipt-number lookup tests in Modules/Pos/Tests/Feature/POSReturnLookupTest.php"
Task: "T025 [P] [US1] Add Livewire snapshot rendering tests for header, owner groups, payments, dispatch status, returnable lines, and hash in tests/Feature/Livewire/PosReturn/PosReturnCreateFormTest.php"
```

## Parallel Example: User Story 2

```bash
Task: "T036 [P] [US2] Add payment return submission tests for non-bundle POS lines in Modules/Pos/Tests/Feature/POSReturnSubmissionTest.php"
Task: "T039 [P] [US2] Add bundle expansion, proportional quantity, stockless component, and parent-only rejection tests in Modules/Pos/Tests/Feature/POSReturnBundleSubmissionTest.php"
Task: "T040 [P] [US2] Add cumulative dispatch quantity cap tests for partial, duplicate, rejected, deleted, archived, and completed returns in Modules/Pos/Tests/Feature/POSReturnQuantityGuardTest.php"
```

## Parallel Example: User Story 3

```bash
Task: "T055 [P] [US3] Add approve and reject lifecycle tests with actor, timestamp, and rejection reason assertions in Modules/Pos/Tests/Feature/POSReturnApprovalWorkflowTest.php"
Task: "T057 [P] [US3] Add payment return settlement allowed/blocked option and cap tests in Modules/Pos/Tests/Feature/POSReturnPaymentReturnWorkflowTest.php"
Task: "T058 [P] [US3] Add replacement dispatch allowed/blocked option, same-SKU, same-quantity, and stock-availability tests in Modules/Pos/Tests/Feature/POSReturnReplacementDispatchWorkflowTest.php"
```

## Parallel Example: User Story 4

```bash
Task: "T076 [P] [US4] Add split-owner POS return mapping tests in Modules/Pos/Tests/Feature/POSReturnSplitOwnerMappingTest.php"
Task: "T078 [P] [US4] Add replacement source owner/location constraint tests in Modules/Pos/Tests/Feature/POSReturnReplacementSourceConstraintTest.php"
Task: "T079 [P] [US4] Add serial-tracked split-owner return tests in Modules/Pos/Tests/Feature/POSReturnSerialSplitOwnerTest.php"
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
2. Add US2 submission for payment return and product replacement.
3. Add US3 approval, receiving, payment return settlement, replacement dispatch, and audited cancellation controls.
4. Add US4 split-owner, owner/location, dispatch quantity, and serial hardening.
5. Run quickstart validation after each story checkpoint.

### Parallel Team Strategy

1. One developer owns migrations/entities and shared service scaffolding in Phase 2.
2. One developer owns POS permissions, role matrix, routes, and menu integration in Phase 1 and Phase 2.
3. After Phase 2, separate developers can draft US1, US2, US3, and US4 tests in parallel.
4. Implementation should merge in priority order to keep the MVP demonstrable.

---

## Independent Test Criteria

- **US1**: Valid completed POS transaction code or receipt number populates an immutable snapshot; unknown, unposted, cancelled, ambiguous, out-of-scope, and fully returned transactions are blocked.
- **US2**: Payment return and product replacement returns persist correct POS Return lines and linked Sales Return records; bundle returns expand all components; stale snapshots, missing serials, and over-quantity submissions are blocked.
- **US3**: Approval gates receiving; approved-before-receiving archive/cancel is audited; receiving gates payment return settlement and replacement dispatch; option-specific lifecycle actions are mutually exclusive and atomic.
- **US4**: Split-owner returns preserve original sale, dispatch detail, owner, location, tax, and serial identity; received returns reduce only the matching dispatch detail quantity.

## Notes

- Suggested MVP scope is Phase 1, Phase 2, and Phase 3 (US1).
- Use `php artisan test --filter=POSReturn`, `php artisan test --filter=PosReturn`, and `composer test:fresh-sqlite -- --filter=POSReturn` for verification.
- Create and apply production migrations through Laravel migration tooling against MySQL/MariaDB; SQLite is only for focused unit/integration test execution.
- Keep POS-specific entry points in `Modules/Pos` and reuse existing Sales Return lifecycle behavior instead of creating a separate return engine.
