## Why

Sales notes become read-only on the detail page once a sale leaves the normal full-edit workflow, forcing users to leave useful operational context unchanged on approved, dispatched, returned, or otherwise lifecycle-locked sales. Purchase details already solve this with a narrowly scoped, permission-gated note editor that is independent of document status, and sales should provide the same capability.

## What Changes

- Add an inline sales-note editor to the normal, setting-scoped sales detail view.
- Allow users with `sales.edit` to update only the `note` field on any non-archived sale, regardless of lifecycle status.
- Preserve the existing archived-record and active-setting boundaries, and keep cross-business/global sales views read-only.
- Validate notes as optional text with a maximum length of 1,000 characters and normalize an empty value to `null`.
- Keep broader approved-sale and dispatched-sale edits governed by their existing lifecycle-specific permissions and services.

## Capabilities

### New Capabilities

- `sales-detail-inline-maintenance`: Provides status-independent, permission-gated inline maintenance of a sale note from the normal sales detail view.

### Modified Capabilities

None.

## Impact

- Affected UI: the note section of the normal sales detail page.
- Affected application code: a new sales Livewire note-editor component and its Blade view.
- Affected authorization: reuses `sales.edit`; no new permission is introduced.
- Affected data: updates only the existing `sales.note` column; no database migration is required.
- Affected tests: focused Livewire coverage for lifecycle states, authorization, archive and setting isolation, validation, and mutation scope.
