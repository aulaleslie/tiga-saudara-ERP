## Why

The POS returns list currently hides the draft actions operators need, and the edit route still conflicts with the intended draft-only edit/delete lifecycle. Draft POS returns also lack a clear "Ajukan Persetujuan" transition, leaving users without a direct path from reversible intake draft to approver review.

## What Changes

- Show draft-only row actions on `/pos/returns` for authorized users:
  - `Edit` for users with `pos.returns.edit`.
  - `Delete` for users with `pos.returns.delete`.
  - `Ajukan Persetujuan` for users allowed to submit POS return drafts.
- Add a draft submission transition that validates the persisted draft and moves it from `draft` / `draft` approval state to `pending_approval` / `pending`.
- Keep `Ajukan Persetujuan` distinct from approver approval; approval still starts only after the return is pending approval.
- Restrict this change to draft POS returns only. Rejected edit/delete behavior is not expanded by this change.
- Make the edit page use the same return-line UI and behavior surface as the create page, differing only in lookup absence, preloaded selections, context labels, navigation, and submit button text.
- Preserve the rule that draft edit, delete, and submit-to-approval do not create Sales Return records, mutate stock, reduce dispatch quantities, settle payments, dispatch replacement items, or write inventory transaction history.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `pos-return-draft-resolutions`: Adds draft-only list actions, draft submit-to-pending-approval behavior, and shared create/edit form surface requirements while keeping draft operations execution-free.

## Impact

- Affected POS return code under `Modules/Pos` and `resources/views/livewire/pos-return`, including routes, controller lifecycle actions, Livewire table/actions, create/edit Livewire form views, POS Return model helpers, submission/lifecycle service boundaries, and feature tests.
- No new external dependencies.
- No destructive schema change is expected; this should primarily use existing `pos_returns.status`, `pos_returns.approval_status`, audit fields, and draft line tables.
- Existing approval, receiving, settlement, dispatch, and audited archive/cancel workflows remain separate lifecycle actions and must not be invoked by draft edit/delete/submit.
