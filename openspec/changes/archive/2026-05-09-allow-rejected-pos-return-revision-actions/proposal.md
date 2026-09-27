## Why

Submitted POS Return drafts correctly become locked while awaiting approval, but rejected returns currently remain locked even though operators need to revise or remove them after an approver sends them back. The lifecycle should keep approval audit history while restoring controlled edit/delete options for rejected documents.

## What Changes

- Allow authorized users to edit POS Returns in `rejected` status with `rejected` approval status.
- Saving an edited rejected POS Return resets the document to `draft` / `draft` so it can be reviewed, revised, and submitted again.
- Allow authorized users to delete rejected POS Returns through audited soft delete fields instead of hard deletion.
- Keep draft deletion as hard delete because draft returns have no approval history or execution effects.
- Keep `pending_approval` / `pending`, approved, execution, completed, archived, cancelled, and manual-correction POS Returns locked from edit/delete.
- Make submit-for-approval available to draft operators through `pos.returns.edit` or `pos.returns.create`, while approver approval/rejection remains controlled by `pos.returns.approve`.
- Preserve the no-execution boundary for edit, delete, and submit actions: no Sales Return creation, stock mutation, dispatch quantity reduction, payment settlement, replacement dispatch, or inventory transaction history.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `pos-return-draft-resolutions`: Change rejected POS Return edit/delete requirements from blocked to revision-enabled, define rejected edit reset-to-draft behavior, define rejected audited soft delete behavior, and clarify submit-for-approval permission semantics.

## Impact

- Affected POS Return lifecycle code in `Modules/Pos`, including `PosReturn` state helpers, `PosReturnController`, `PosReturnSubmissionService`, `PosReturnLifecycleService` interactions, Livewire edit form guards, list/detail action rendering, routes or permission checks where needed, and focused feature/Livewire tests.
- No new external dependencies are expected.
- No destructive schema change is expected if existing `deleted_by`, `delete_reason`, `deleted_at`, `rejected_by`, `rejected_at`, and `rejection_reason` fields are sufficient.
- Existing POS, Sales Return, stock, dispatch, payment, serial, and audit behavior must remain unchanged outside the rejected POS Return revision/delete surface.
