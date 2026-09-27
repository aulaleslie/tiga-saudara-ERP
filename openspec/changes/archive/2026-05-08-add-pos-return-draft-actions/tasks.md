## 1. Draft Lifecycle Guards

- [x] 1.1 Add or update POS Return model helpers for draft action eligibility: edit, hard delete, and submit-to-approval must require `status = draft` and draft approval state.
- [x] 1.2 Add a dedicated draft submission service/controller path that validates a persisted draft and moves it to `pending_approval` / `pending` without invoking approval or execution workflows.
- [x] 1.3 Revalidate draft submission inputs using existing snapshot freshness, actionable-line, quantity, resolution, and replacement serial checks where applicable.
- [x] 1.4 Ensure draft submit, edit, and delete endpoints block pending, approved, rejected, manual-correction, archived, cancelled, completed, and deleted returns with clear lifecycle errors.

## 2. Routes And Controllers

- [x] 2.1 Add a route for `Ajukan Persetujuan` on POS Return drafts under the existing authenticated POS return route group.
- [x] 2.2 Update `PosReturnController::edit` to allow draft returns and remove the conflicting pending-approval edit guard.
- [x] 2.3 Update `PosReturnController::destroy` to hard-delete draft returns only for this draft action path.
- [x] 2.4 Add controller handling for draft submit that gates permissions, calls the draft submit service path, shows success/error feedback, and redirects consistently with existing POS return screens.

## 3. List Page Actions

- [x] 3.1 Update the POS Return table query/view as needed so each row can determine draft action eligibility without N+1 or stale status assumptions.
- [x] 3.2 Render `Edit`, `Delete`, and `Ajukan Persetujuan` actions on `/pos/returns` only for draft rows and only when the user has the matching permission.
- [x] 3.3 Add confirmation and form handling for draft delete and draft submit actions consistent with existing Bootstrap/CoreUI conventions.
- [x] 3.4 Verify non-draft rows show no draft actions while retaining existing view/detail navigation.

## 4. Shared Create/Edit Form Surface

- [x] 4.1 Extract the duplicated create/edit return-line markup into a shared Blade partial or equivalent shared Livewire-rendered surface.
- [x] 4.2 Keep create-specific transaction lookup outside the shared surface.
- [x] 4.3 Keep edit-specific header, preloaded selections, cancel/back navigation, and button label outside or parameterized around the shared surface.
- [x] 4.4 Ensure grouped lines, serial resolution buttons, non-serial quantity inputs, replacement serial scanner controls, bundle trace/component availability display, totals, errors, and loading states behave identically in create and edit.

## 5. Tests

- [x] 5.1 Add or update feature tests proving draft list rows show permitted `Edit`, `Delete`, and `Ajukan Persetujuan` actions.
- [x] 5.2 Add or update feature tests proving non-draft rows do not show draft actions.
- [x] 5.3 Add service/controller tests proving valid draft submit changes status to `pending_approval` and approval status to `pending`.
- [x] 5.4 Add tests proving draft submit does not create Sales Return records, Sale Return Details, stock mutations, dispatch quantity reductions, payment settlements, replacement dispatches, serial-status mutations, or inventory transaction history.
- [x] 5.5 Add tests proving draft submit is blocked for stale, empty, invalid, rejected, pending, approved, and terminal returns.
- [x] 5.6 Add Livewire or view-level coverage proving edit preloads draft selections and uses the same return-line controls as create.

## 6. Verification

- [x] 6.1 Run focused POS Return tests with `php artisan test` filters for drafting, draft actions, route authorization, and lifecycle guards.
- [x] 6.2 Manually inspect `/pos/returns` with draft and non-draft records to confirm action visibility and button placement.
- [x] 6.3 Manually inspect create and edit pages for the same POS transaction to confirm shared form behavior, totals, validation placement, and replacement serial controls remain consistent.
