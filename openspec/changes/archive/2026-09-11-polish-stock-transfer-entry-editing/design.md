## Context

The stock-transfer entry workflow already uses a shared Livewire form and authoritative `TransferDraftService`/`TransferLifecycleService` boundaries. It permits the origin business to edit `DRAFT` and `PENDING` records, but the list only advertises editing for `PENDING`, the stock condition remains mutable in the form, and line changes to a pending request currently leave it eligible for approval. Existing optimistic revision locking and action history provide the primitives needed to correct this without changing the schema or inventory workflow.

## Goals / Non-Goals

**Goals:**

- Make creation-time stock-condition selection clear and accessible.
- Make saved drafts discoverably editable while preserving permission and origin ownership boundaries.
- Make condition immutable after the transfer is first persisted.
- Ensure a materially revised pending request cannot be approved until explicitly resubmitted.
- Preserve atomic line synchronization, revision checks, and lifecycle history.
- Verify the behavior with focused automated tests and a human browser checklist.

**Non-Goals:**

- Inventory allocation or movement changes.
- Dispatch, receipt, return, or approval-policy redesign.
- New permissions or role migration.
- Automated end-to-end browser tests or a mandatory full-suite run.
- Repairing or rewriting historical mixed-condition records.

## Decisions

### Condition is selectable only before initial persistence

The create form will use two real radio controls styled as a segmented choice for `GOOD` and `BREAKAGE`. Labels, checked state, focus treatment, disabled treatment, and validation text will make state understandable without color alone. Product entry remains gated by a valid origin and condition. If the creation-time condition changes after rows exist, the UI will require confirmation before clearing incompatible rows.

An edit form will not expose writable condition controls. It will render the persisted value as a read-only badge or label and always derive validation and product lookup mode from the persisted transfer. The Livewire and service boundaries will reject any attempted condition mismatch. This is preferred over a disabled form control because disabled inputs are presentation-only and are not a security or integrity boundary.

Historical mixed-condition records will remain viewable but will not enter the ordinary edit workflow. This avoids inventing a condition or deleting historical lines.

### Edit discovery follows the same lifecycle guard as the edit endpoint

The index action partial will advertise edit for `DRAFT` and `PENDING` only, guarded by `stockTransfers.edit`. Direct controller and Livewire mutations will continue to require the active business to own the origin. `APPROVED` and all later states remain immutable regardless of how the endpoint is invoked.

Keeping list visibility and endpoint eligibility aligned removes the current navigation gap without treating UI visibility as authorization.

### Pending revision withdrawal occurs only on a material save

Opening an edit page has no lifecycle effect. At save time, the service will lock the transfer using its revision, authoritatively validate the submitted state, normalize the header and line representation, and compare it with the persisted representation.

For a material change to a `PENDING` transfer, one transaction will synchronize lines, set status to `DRAFT`, increment the revision, and append an edit/withdrawal history entry with `from_status=PENDING` and `to_status=DRAFT`. Approval therefore cannot consume the revised request until the editor explicitly resubmits it through the existing draft submission boundary.

For a no-op submission, the service will preserve status, revision, lines, and history. Canonical comparison will ignore presentation-only Livewire data and ordering that has no domain meaning. This avoids manufacturing revisions merely because the form was saved.

A material `DRAFT` edit remains `DRAFT`, increments revision, and records `DRAFT` to `DRAFT` edit history. The existing optimistic lock prevents an approval or another editor from silently racing the save.

### Existing persistence boundaries remain authoritative

Both the shared Livewire form and the legacy controller update path will use the same draft service semantics. UI state will not decide whether a request is materially changed or editable. Origin ownership, destination validity, product eligibility, quantities, serials, and condition consistency remain server validated before persistence.

## Risks / Trade-offs

- **[Canonical comparison misses a meaningful line field]** → Define the comparison from the same normalized transfer/form DTO fields used for persistence and cover quantities, bucket intent, serial selections, destination, and product identity with focused tests.
- **[Canonical comparison treats harmless ordering as a mutation]** → Sort normalized lines and serial identifiers before comparison.
- **[Concurrent approval races a pending edit]** → Retain row locking and revision checks inside the same transaction that performs comparison, synchronization, transition, and history recording.
- **[Read-only condition is modified through a crafted request]** → Ignore presentation state for existing records and reject any state whose condition differs from the persisted condition at the service boundary.
- **[Users mistake the pending-to-draft transition for data loss]** → Provide clear success feedback that the revision was saved as a draft and must be resubmitted.
- **[Historical mixed records cannot be corrected in this workflow]** → Keep them view-only; any repair workflow requires a separately authorized change.

## Migration Plan

No database migration or data backfill is required. Deploy the UI, controller, service, and focused-test changes together. Rollback consists of reverting those application changes; existing transfer rows and history remain compatible.

Human browser verification will confirm segmented-control interaction and focus, condition-change confirmation during creation, read-only edit presentation, draft edit discovery, and pending-edit feedback.

## Open Questions

None.
