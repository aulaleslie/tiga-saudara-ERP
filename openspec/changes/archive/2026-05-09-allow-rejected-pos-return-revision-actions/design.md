## Context

POS Return drafts are reversible intake documents stored in `pos_returns` and `pos_return_lines`. Draft edit/delete/submit actions are intended to remain execution-free, while approval, receiving, settlement, dispatch, archive, and cancel actions are separate lifecycle transitions.

The current implementation already distinguishes draft, pending approval, approved, rejected, and terminal statuses. It also already has model helpers for rejected editability and rejected soft deletion, but the controller, Livewire edit form, list actions, detail actions, and submission service still enforce draft-only edit/delete. Rejection currently records rejection audit fields and sets `status = rejected` and `approval_status = rejected`, which is the right audit state to preserve.

## Goals / Non-Goals

**Goals:**

- Keep POS Returns locked while submitted for approval.
- Let authorized operators edit rejected POS Returns without erasing rejection audit fields.
- Reset a rejected POS Return to `draft` / `draft` only after a successful rejected edit save.
- Let authorized operators delete rejected POS Returns through audited soft delete.
- Keep draft hard delete behavior unchanged.
- Move submit-for-approval permission away from approver-only semantics and allow users with draft authoring permission to submit.
- Preserve execution-free behavior for draft/rejected edit, draft/rejected delete, and submit-for-approval.

**Non-Goals:**

- Changing approver approval or rejection semantics beyond the state reached after rejection.
- Adding a new `revision_required` status.
- Creating linked Sales Return documents during edit, delete, or submit-for-approval.
- Mutating stock, dispatch quantities, serial execution state, payments, replacement dispatch, or inventory transaction history during these actions.
- Changing approved, received, settled, dispatched, completed, archived, cancelled, or manual-correction lifecycle behavior.

## Decisions

### Decision 1: Rejected Remains Rejected Until A Successful Edit Save

Rejected POS Returns stay in `rejected` / `rejected` immediately after approver rejection. The rejected state becomes editable and soft-deletable for authorized users. A successful rejected edit save rebuilds draft lines and resets the return to `draft` / `draft`.

Rationale: this preserves the approver decision and rejection reason until the operator actually revises the document. It also avoids making rejected documents look like ordinary untouched drafts.

Alternative considered: reject directly back to `draft` / `draft`. Rejected because it weakens the visible audit state and makes rejection indistinguishable from an unsubmitted draft unless users inspect audit fields.

### Decision 2: Reuse Draft Edit Validation For Rejected Revision

Rejected edit should use the same line selection, source snapshot freshness, replacement serial validation, and actionable-line validation as draft edit. The update boundary should accept either `isDraftEditable()` or `isRejectedEditable()`, then persist the edited return as `draft` / `draft`.

Rationale: rejected revision is operationally the same as correcting a draft after reviewer feedback. Reusing draft validation keeps serial, bundle, no-action, quantity, and replacement behavior consistent.

Alternative considered: create a separate rejected-edit form/service path. Rejected because it would duplicate the highest-risk draft return behavior and increase drift risk.

### Decision 3: Keep Delete Semantics Split By Audit History

Draft POS Returns remain hard-deletable. Rejected POS Returns use soft delete and record `deleted_by` and an optional delete reason where provided.

Rationale: draft documents have no approval decision and no execution effects, so hard delete remains appropriate. Rejected documents have approval history and should retain an audit trail even when removed from active lists.

Alternative considered: hard-delete rejected returns too. Rejected because it discards approval and rejection history.

### Decision 4: Submit Uses Draft Authoring Permission, Not Approver Permission

Submitting a draft to approval should be authorized for users who can create or edit POS Returns. Approver-only `pos.returns.approve` remains required for approval and rejection decisions.

Rationale: submit-for-approval is an operator handoff, not an approver action. This separates workflow ownership from approval authority.

Alternative considered: keep using `pos.returns.approve` for submit. Rejected because it prevents normal draft operators from submitting their own returns and conflates submission with approval.

### Decision 5: UI Visibility Follows The Same State Helpers As Server Guards

List and detail actions should use model helpers for draft edit, rejected edit, hard delete, rejected soft delete, and draft submit eligibility. Server-side guards remain authoritative for crafted requests.

Rationale: keeping UI conditions close to state helper semantics reduces mismatch between visible actions and request outcomes.

Alternative considered: check raw status strings separately in each view. Rejected because it repeats lifecycle logic across Blade and controller surfaces.

## Risks / Trade-offs

- Rejected edit could accidentally clear rejection audit fields -> Keep `rejected_by`, `rejected_at`, and `rejection_reason` intact unless a later explicit audit cleanup requirement says otherwise.
- Soft-deleted rejected rows could disappear from normal lists but remain counted elsewhere -> Use existing SoftDeletes behavior and ensure active list queries keep their current default non-trashed behavior.
- Submit permission change can expose submit buttons to users who previously did not see them -> Limit approval/rejection endpoints to `pos.returns.approve` and cover permission behavior with tests.
- Reusing draft update for rejected edit could accidentally allow pending edit if helper conditions are too broad -> Keep accepted edit states explicit: draft/draft or rejected/rejected only.
- Rejected delete may need a reason prompt in UI -> Treat reason as optional unless a stricter business rule is requested, but always record actor and soft-delete timestamp.

## Migration Plan

- No schema migration is expected if existing delete and rejection audit columns are present.
- Deploy model helper, controller/service guard, view action, permission, and test changes together.
- Existing rejected POS Returns become editable and soft-deletable after deployment when the user has the required permission.
- Existing draft, pending, approved, execution, completed, archived, cancelled, and manual-correction returns keep their current lifecycle state.
- Rollback is code-only; rejected returns edited during the deployment window will already have been reset to draft by normal business action.

## Open Questions

None. The agreed behavior is rejected state remains visible after rejection, rejected edit resets to draft after successful save, rejected delete is audited soft delete, and submit-for-approval uses draft authoring permission.
