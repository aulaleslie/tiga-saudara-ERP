# Tasks: Harden Purchase Report Validity

**Input**: Design documents from `/specs/20260429-234320-harden-purchase-report/`
**Prerequisites**: plan.md (required), spec.md (required for user stories), research.md, data-model.md, contracts/, quickstart.md

**Tests**: Included because the feature spec defines mandatory testing scenarios and measurable outcomes for validation, export parity, and searchable filter behavior.

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (e.g., US1, US2, US3)
- Include exact file paths in descriptions

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Prepare the feature workspace and baseline tests for purchase report hardening.

- [x] T001 Review current purchase report behavior and fixtures in `app/Livewire/Reports/PurchaseReport.php`, `app/Exports/PurchaseReportExport.php`, and `Modules/Reports/Tests/Feature/`
- [x] T002 Create/refresh feature test scaffold for report hardening in `Modules/Reports/Tests/Feature/PurchaseReportHardeningTest.php`
- [x] T003 [P] Create/refresh export parity test scaffold in `Modules/Reports/Tests/Feature/PurchaseReportExportParityTest.php`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Build shared filter validation/query/snapshot infrastructure used by all stories.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete

- [x] T004 Create validated filter DTO with array support for `supplierIds` and `tagIds` in `app/Services/Reports/PurchaseReportFilterData.php`
- [x] T005 [P] Create snapshot state object for latest successful report run in `app/Services/Reports/PurchaseReportSnapshot.php`
- [x] T006 Implement shared validation rules for filter payloads, supporting arrays, in `app/Services/Reports/PurchaseReportValidator.php`
- [x] T007 Implement shared query builder with array `whereIn`/`whereHas` and active payment status derivation in `app/Services/Reports/PurchaseReportQueryService.php`
- [x] T008 Implement snapshot hash generation and export precondition helpers in `app/Services/Reports/PurchaseReportSnapshotService.php`

**Checkpoint**: Foundation ready; user story implementation can now begin.

---

## Phase 3: User Story 1 - Generate Valid On-Screen Purchase Report (Priority: P1) 🎯 MVP

**Goal**: Ensure on-screen results are strictly validated, scope-safe, deterministic for empty states, and usable at scale via searchable multi-select Supplier/Tag filters and consistent CoreUI styling.

### Tests for User Story 1

- [x] T009 [P] [US1] Add feature test for valid filter application and row correctness in `Modules/Reports/Tests/Feature/PurchaseReportHardeningTest.php`
- [x] T010 [P] [US1] Add feature test for non-global scope restriction by `setting_id` in `Modules/Reports/Tests/Feature/PurchaseReportHardeningTest.php`
- [x] T011 [P] [US1] Add feature test for deterministic empty-state behavior in `Modules/Reports/Tests/Feature/PurchaseReportHardeningTest.php`
- [x] T012 [P] [US1] Add Livewire component test for Supplier/Tag multi-select lookup threshold and pill state in `Modules/Reports/Tests/Feature/PurchaseReportHardeningTest.php`

### Implementation for User Story 1

- [x] T013 [US1] Refactor `Tampilkan Laporan` flow to use shared validator and query service in `app/Livewire/Reports/PurchaseReport.php`
- [x] T014 [US1] Persist latest successful validated filter hash/snapshot in component state/session in `app/Livewire/Reports/PurchaseReport.php`
- [x] T015 [US1] Implement multi-select state arrays (`$supplierIds`, `$selectedTags`) and action methods (`selectSupplier`, `removeSupplier`, etc) in `app/Livewire/Reports/PurchaseReport.php`
- [x] T016 [US1] Implement server-side Supplier/Tag lookup methods excluding already-selected IDs in `app/Livewire/Reports/PurchaseReport.php`
- [x] T017 [US1] Update Supplier/Tag UI controls in Blade for pill-based multi-select, debounce, and dismiss-on-select interaction in `resources/views/livewire/reports/purchase-report.blade.php`
- [x] T018 [US1] Update Pajak, Status, and Status Pembayaran `<select>` controls to use CoreUI `form-control` styling in `resources/views/livewire/reports/purchase-report.blade.php`
- [x] T019 [US1] Update on-screen table rendering and empty-state messaging to consume canonical result set in `resources/views/livewire/reports/purchase-report.blade.php`
- [x] T020 [US1] Ensure report route/controller still delegates to Livewire flow without scope regressions in `Modules/Reports/Http/Controllers/PurchaseReportController.php`

**Checkpoint**: User Story 1 should be fully functional and independently testable.

---

## Phase 4: User Story 2 - Export Data That Matches On-Screen Results (Priority: P2)

**Goal**: Block invalid export attempts and guarantee Excel/CSV/PDF outputs match the latest successful on-screen snapshot.

### Tests for User Story 2

- [x] T021 [P] [US2] Add feature test that export is blocked before successful `Tampilkan Laporan` snapshot in `Modules/Reports/Tests/Feature/PurchaseReportExportParityTest.php`
- [x] T022 [P] [US2] Add feature test for snapshot invalidation when array filters change before export in `Modules/Reports/Tests/Feature/PurchaseReportExportParityTest.php`
- [x] T023 [P] [US2] Add feature test for export parity (row count, identifiers) across Excel/CSV/PDF in `Modules/Reports/Tests/Feature/PurchaseReportExportParityTest.php`

### Implementation for User Story 2

- [x] T024 [US2] Refactor export pipeline to consume shared validated query contract and array payloads in `app/Exports/PurchaseReportExport.php`
- [x] T025 [US2] Enforce export precondition checks and clear user messaging in `app/Livewire/Reports/PurchaseReport.php`
- [x] T026 [US2] Keep export endpoint wiring consistent while propagating array payload in `Modules/Reports/Routes/web.php`
- [x] T027 [US2] Ensure PDF/Excel/CSV period metadata is sourced from canonical validated dates in `app/Exports/PurchaseReportExport.php`

**Checkpoint**: User Stories 1 and 2 work independently with export parity guarantees.

---

## Phase 5: User Story 3 - Prevent Invalid Report Inputs (Priority: P3)

**Goal**: Reject contradictory/out-of-contract inputs with clear feedback and no report/export side effects.

### Tests for User Story 3

- [x] T028 [P] [US3] Add feature test for `endDate < startDate` validation failure in `Modules/Reports/Tests/Feature/PurchaseReportHardeningTest.php`
- [x] T029 [P] [US3] Add feature test for invalid tax/status/payment-status value rejection in `Modules/Reports/Tests/Feature/PurchaseReportHardeningTest.php`

### Implementation for User Story 3

- [x] T030 [US3] Apply strict allowed-option and existence validation messages for report submission in `app/Livewire/Reports/PurchaseReport.php`
- [x] T031 [US3] Reuse the same validation guard for export-triggered requests in `app/Livewire/Reports/PurchaseReport.php`
- [x] T032 [US3] Normalize payment status, tax flag, and status values to canonical contract values in `app/Services/Reports/PurchaseReportValidator.php`

**Checkpoint**: All user stories are independently functional with invalid input prevention.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Final verification, cleanup, and confidence checks across all stories.

- [x] T033 [P] Run focused verification suite for purchase report hardening via `composer test:fresh-sqlite -- --filter=PurchaseReport`
- [x] T034 [P] Run quickstart manual scenario checklist and capture verification notes in `specs/20260429-234320-harden-purchase-report/quickstart.md`
- [x] T035 Review and tidy duplicated legacy filtering code paths/comments in `app/Livewire/Reports/PurchaseReport.php` and `app/Exports/PurchaseReportExport.php`
- [x] T036 [P] Create a performance verification seeder to generate 1,000+ Suppliers/Tags and verify searchable dropdown responsiveness in `Modules/Reports/Tests/Feature/`

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies; can start immediately.
- **Foundational (Phase 2)**: Depends on Setup completion; blocks all user stories.
- **User Stories (Phase 3+)**: Depend on Foundational completion.
  - Preferred order: US1 (P1) -> US2 (P2) -> US3 (P3)
  - US2 depends on US1 snapshot/report-run flow.
  - US3 can start after Foundational and should align with US1 validation plumbing.
- **Polish (Phase 6)**: Depends on all targeted stories being complete.

### Within Each User Story

- Test tasks first, then implementation tasks.
- Validation/query contract integration before UI/export wiring.
- Core behavior before cleanup/polish.

### Parallel Opportunities

- T002 and T003 can run in parallel.
- In Foundational phase, T004 and T005 can run in parallel; T006/T007 depend on T004.
- In US1 tests, T009-T012 can run in parallel.
- In US2 tests, T021-T023 can run in parallel.
- In US3 tests, T028-T029 can run in parallel.
- In Polish, T033 and T034 can run in parallel.

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1: Setup.
2. Complete Phase 2: Foundational.
3. Complete Phase 3: User Story 1.
4. Validate US1 independently before enabling export hardening rollout.

### Incremental Delivery

1. Ship US1 for trusted on-screen reporting, searchable multi-select Supplier/Tag filtering, and CoreUI standard select styling.
2. Add US2 for export parity and snapshot preconditions.
3. Add US3 to close invalid-input gaps and finalize UX clarity.
4. Complete Polish and regression checks.
