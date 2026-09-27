# Tasks: Fix Product Quick Add Modal Reset

**Input**: Design documents from `/specs/20260429-230139-fix-product-quick-add-reset/`
**Prerequisites**: plan.md (required), spec.md (required for user stories)

**Organization**: Tasks are grouped by user story to enable independent implementation and testing of each story.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (e.g., US1, US2, US3)
- Include exact file paths in descriptions

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Initial discovery and test preparation

- [x] T001 Identify existing Livewire tests for product modals in `tests/Feature/Livewire/Product/`
- [x] T002 Create reproduction test case `tests/Feature/Livewire/Product/ProductQuickAddResetTest.php` to verify properties are NOT reset currently

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Core logic updates that MUST be complete before UI changes

- [x] T003 Refine `resetForm()` in `app/Livewire/Modules/Product/Modals/ProductQuickAddModal.php` to ensure explicit reset of `product_name` and `is_sold`

**Checkpoint**: Foundation ready - UI implementation can now begin

---

## Phase 3: User Story 1 - Reset Modal State After Creation (Priority: P1) 🎯 MVP

**Goal**: Ensure the modal is fully cleared and ready for next entry after successful product creation

**Independent Test**: Run `php artisan test tests/Feature/Livewire/Product/ProductQuickAddResetTest.php`

### Implementation for User Story 1

- [x] T004 [P] [US1] Add `wire:key` to `product_name` input in `resources/views/livewire/modules/product/modals/product-quick-add-modal.blade.php` using `formResetVersion`
- [x] T005 [P] [US1] Add `wire:key` to `is_sold` checkbox wrapper in `resources/views/livewire/modules/product/modals/product-quick-add-modal.blade.php` using `formResetVersion`
- [x] T006 [US1] Verify fix using the new test case `tests/Feature/Livewire/Product/ProductQuickAddResetTest.php`

**Checkpoint**: At this point, User Story 1 should be fully functional and testable independently

---

## Phase 4: Polish & Cross-Cutting Concerns

**Purpose**: Final verification

- [x] T007 Manual verification of product creation and modal clearing in Purchase Create screen

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: Can start immediately.
- **Foundational (Phase 2)**: Depends on Setup (T002 provides the test to fail).
- **User Story 1 (Phase 3)**: Depends on Foundational completion.
- **Polish (Final Phase)**: Depends on all tasks in Phase 3 completion.

### Parallel Opportunities

- T004 and T005 can be done in parallel as they modify different parts of the same Blade file (or can be done together).

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Create a failing test case.
2. Implement PHP logic changes.
3. Implement Blade UI changes.
4. Verify with the test case.
5. Manually verify in the browser.
