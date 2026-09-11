## Why

Saved stock-transfer drafts are difficult to reopen, while edits to a pending request currently remain pending and can expose revised lines to approval without an explicit resubmission. The entry form also presents stock condition as an ordinary dropdown even though condition is a defining, immutable property of an existing transfer.

## What Changes

- Present good-stock versus breakage-stock selection as an accessible segmented control when creating a transfer.
- Treat the persisted stock condition as immutable after initial creation and render it as read-only context on edit screens.
- Expose the edit action for both `DRAFT` and `PENDING` transfers when the user has edit permission and owns the origin through the active business.
- When a material edit to a `PENDING` transfer is saved, atomically return it to `DRAFT`, advance its revision, record the transition, and require explicit resubmission.
- Avoid a revision or lifecycle transition when an edit submission contains no material change.
- Preserve existing origin-ownership checks and reject edits to approved or later lifecycle states.
- Keep historical mixed-condition transfers view-only and preserve their recorded contents.
- Add focused automated verification plus a human browser-verification checklist; no full-suite or automated browser-test requirement is introduced.
- Make no changes to inventory movement behavior.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `stock-transfer-entry-scanning`: Refine condition selection and edit hydration, make drafts discoverably editable, and require pending revisions to return to draft before approval.

## Impact

The change affects the stock-transfer Livewire/Blade entry form, transfer-list actions, edit/update authorization paths, draft and lifecycle services, action history, and focused feature/Livewire/unit tests in `Modules/Adjustment`. It does not alter transfer movement, inventory allocation, dispatch, receipt, return, permission names, or external APIs.
