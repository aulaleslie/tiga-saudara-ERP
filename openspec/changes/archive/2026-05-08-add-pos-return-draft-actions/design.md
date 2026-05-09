## Context

POS Return drafting already persists a reversible intake document (`pos_returns` plus `pos_return_lines`) without execution-side Sales Return, stock, dispatch, payment, or inventory-history effects. The remaining gap is lifecycle and UI clarity: `/pos/returns` exposes only a detail action, the controller edit guard still targets `pending_approval`, and there is no explicit draft submission action that moves a saved draft into approver review.

The current create and edit Livewire forms also duplicate much of the return-line rendering. That duplication is risky because the POS return form has source-sensitive behavior: POS transaction line grouping, serial-level resolutions, replacement serial validation, bundled component trace display, returnable quantity calculations, and expected cash return totals.

## Goals / Non-Goals

**Goals:**

- Expose `Edit`, `Delete`, and `Ajukan Persetujuan` actions for draft POS returns on the list page.
- Enforce draft-only behavior for these actions in both UI and server-side guards.
- Add a narrow draft submission transition from `draft` / `draft` approval state to `pending_approval` / `pending`.
- Revalidate the persisted draft before submission so stale, empty, or invalid draft data cannot enter approval.
- Share the create/edit return-line form surface so edit remains visually and behaviorally consistent with create.
- Preserve execution-free draft semantics through create, edit, delete, and submit-to-approval.

**Non-Goals:**

- Changing approver approval/rejection behavior for `pending_approval` returns.
- Adding rejected return edit/delete behavior in this change.
- Creating linked Sales Return records during draft edit/delete/submit.
- Receiving returned goods, settling cash returns, dispatching replacements, or adjusting dispatch/stock/payment data.
- Redesigning unrelated POS return detail, approval, receiving, settlement, or dispatch screens.

## Decisions

### Decision 1: Model Draft Submit As Its Own Lifecycle Transition

Add a dedicated draft submission path instead of reusing approve. The transition accepts only POS Returns where `status = draft` and `approval_status = draft`, validates the persisted header and lines, then updates to `status = pending_approval` and `approval_status = pending`.

Rationale: `Ajukan Persetujuan` is an intake-user action, while `approve` is an approver action. Keeping the transitions separate preserves permission semantics and prevents a list button from accidentally skipping review.

Alternative considered: post directly to the existing approve endpoint. Rejected because approval currently requires pending approval state and represents an approver decision, not draft submission.

### Decision 2: Validate Persisted Draft Data Before Pending Approval

Before moving to pending approval, rebuild or validate the source snapshot hash where practical, require at least one actionable line, require valid line resolutions and quantities, and re-run replacement serial availability checks for product replacement serial lines. The transition remains execution-free.

Rationale: saving a draft can happen earlier than submission, so the submit action must protect approvers from stale or incomplete documents.

Alternative considered: trust data saved during draft creation/edit. Rejected because source quantities, serial availability, and draft contents can drift between save and submit.

### Decision 3: Keep Draft Actions Visible Only For Draft Rows

The list table should render draft action buttons only for `draft` status rows and only when the current user has the matching permission. Server endpoints still enforce the same draft-only rule even if a request is crafted manually.

Rationale: the list should communicate lifecycle state clearly and avoid offering actions that will fail for pending, approved, rejected, archived, cancelled, completed, or manual-correction returns.

Alternative considered: show disabled buttons with tooltips for non-draft statuses. Rejected because the existing table is compact and the primary need is actionable draft cleanup/submission.

### Decision 4: Share The Return-Line Form Surface

Extract or otherwise centralize the create/edit return-line rendering and interaction surface. Create keeps the lookup step; edit starts from an existing POS Return and preloads selections. The shared surface owns grouped line display, resolution controls, quantity input, replacement serial scanner fields, component trace/availability display, cash total summary, validation message placement, and loading states.

Rationale: the return-line UI is the highest-risk area for drift. Shared rendering keeps create and edit aligned while allowing small contextual differences in header, back/cancel navigation, and submit labels.

Alternative considered: manually keep two Blade files visually similar. Rejected because this has already produced guard and behavior mismatch, and future POS return line changes would need duplicate edits.

### Decision 5: Narrow Rejected Behavior Out Of This Change

This change defines list actions and server guards for draft returns only. Rejected return edit/delete behavior should not be expanded as part of these tasks.

Rationale: rejected returns have approval history and audit concerns that differ from reversible drafts. Keeping this change draft-only reduces lifecycle ambiguity and matches the clarified requirement.

Alternative considered: include rejected edit/delete from the earlier draft-resolution design. Rejected for this change because the requested operational gap is draft document handling before approval submission.

## Risks / Trade-offs

- Draft submit could accidentally create execution records if routed through existing approval logic → Keep a dedicated submit method that only updates POS Return status fields and audit fields.
- Shared form extraction could become too large → Limit sharing to the return-line form surface and keep lookup/edit shell context outside the shared partial/component.
- Existing tests may assume direct draft-to-pending behavior from earlier implementations → Update tests to distinguish draft save from draft submit and approver approval.
- Rejected behavior may remain inconsistent with old artifacts → The spec delta explicitly narrows this change to draft-only actions; rejected lifecycle can be handled by a separate proposal if needed.
- Stale snapshot validation may be expensive on list submit → Use the same snapshot/quantity guards already used by create/edit and keep the action focused on one return at a time.

## Migration Plan

- No database migration is expected.
- Deploy route/controller/model/service/view/test changes together.
- Existing draft POS Returns remain draft and gain list actions when the user has permission.
- Existing pending/approved/rejected/terminal returns remain unchanged and do not receive draft-only list actions.
- Rollback removes the new draft submit action and shared form extraction; since submit-to-approval only changes POS Return status fields, operational rollback can move affected records back to draft manually if needed before approval occurs.

## Open Questions

- None. The clarified scope is draft-only edit, hard delete, and submit-to-pending-approval with a shared create/edit form surface.
