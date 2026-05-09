## 1. State Helpers And Authorization

- [x] 1.1 Review `PosReturn` helper methods and add or adjust explicit helpers for draft edit, rejected edit, draft submit, draft hard delete, rejected soft delete, and locked pending/terminal states.
- [x] 1.2 Change submit-for-approval authorization so users with `pos.returns.create` or `pos.returns.edit` can submit valid drafts while approval and rejection decisions still require `pos.returns.approve`.
- [x] 1.3 Ensure pending approval, approved, execution, completed, archived, cancelled, and manual-correction states remain blocked from edit/delete server-side.

## 2. Rejected Edit Flow

- [x] 2.1 Update POS Return edit controller and Livewire mount guards to allow `draft/draft` and `rejected/rejected` returns only.
- [x] 2.2 Update `PosReturnSubmissionService::update()` to accept draft or rejected returns, reuse draft validation, rebuild lines, and reset successful rejected edits to `draft/draft`.
- [x] 2.3 Preserve `rejected_by`, `rejected_at`, and `rejection_reason` fields when a rejected return is edited back to draft.
- [x] 2.4 Verify failed rejected edits leave existing lines, status, approval status, and rejection audit fields unchanged.

## 3. Rejected Delete Flow

- [x] 3.1 Update delete handling so draft returns still force-delete header and draft lines.
- [x] 3.2 Add rejected delete handling that records `deleted_by` and optional `delete_reason`, then soft-deletes the rejected return.
- [x] 3.3 Ensure rejected soft delete does not force-delete POS Return lines or mutate Sales Return, stock, dispatch, payment, replacement dispatch, serial, or inventory history tables.
- [x] 3.4 Keep delete requests for pending, approved, execution, completed, archived, cancelled, and manual-correction returns blocked.

## 4. UI Actions

- [x] 4.1 Update `/pos/returns` list actions to show edit/delete for draft and rejected returns according to user permissions and lifecycle state.
- [x] 4.2 Update POS Return detail header actions to show edit/delete for draft and rejected returns according to user permissions and lifecycle state.
- [x] 4.3 Keep submit-for-approval visible only for draft-submittable returns and users with draft authoring permission.
- [x] 4.4 Keep approve/reject buttons visible only for pending approval returns and users with `pos.returns.approve`.
- [x] 4.5 Add or adjust rejected delete confirmation UI to support an optional delete reason if the existing interaction pattern allows it.

## 5. Tests

- [x] 5.1 Add feature coverage showing a draft author without `pos.returns.approve` can submit a valid draft for approval.
- [x] 5.2 Add feature coverage showing a user without `pos.returns.approve` still cannot approve or reject a pending return.
- [x] 5.3 Add feature or Livewire coverage showing pending approval returns cannot be edited or deleted.
- [x] 5.4 Add coverage showing rejected returns can be opened for edit, saved successfully, reset to `draft/draft`, and preserve rejection audit fields.
- [x] 5.5 Add coverage showing failed rejected edit validation leaves status, approval status, lines, and rejection audit fields unchanged.
- [x] 5.6 Add coverage showing rejected delete soft-deletes the return, records `deleted_by`, preserves rejection audit fields, and does not force-delete lines.
- [x] 5.7 Add coverage showing draft delete remains a hard delete.
- [x] 5.8 Add UI/action visibility coverage for draft, pending approval, rejected, approved, and terminal return states where practical.

## 6. Verification

- [x] 6.1 Run focused POS Return lifecycle and draft resolution tests.
- [x] 6.2 Run focused Livewire POS Return edit/table tests.
- [x] 6.3 Run a broader `php artisan test` filter for POS Return if focused tests pass.
- [x] 6.4 Manually inspect that no migration or execution-side mutation is introduced by the rejected edit/delete and submit permission changes.
