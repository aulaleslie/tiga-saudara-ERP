## Why

Draft POS Return edit currently rewrites an explicit serial-line `Tidak` (`none`) selection back to the POS Return header `return_option`, such as `cash_return`. This prevents users from removing one line from an otherwise valid draft return and can even make an all-`Tidak` edit appear valid by converting every line back to cash return.

## What Changes

- Preserve explicit line-level `none` selections during draft edit instead of falling back to the header `return_option`.
- Keep the existing validation rule that a draft POS Return must contain at least one actionable line (`cash_return` or `product_replacement`) before it can be saved.
- Ensure partial `Tidak` edits update the same draft without execution-side mutations.
- Ensure all-`Tidak` edits are rejected and do not silently recreate actionable return lines.
- Add focused regression coverage for the edit path that triggered this behavior.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `pos-return-draft-resolutions`: Draft edit must treat explicit per-line `none` as authoritative and must not use header-level return option fallback to override it.

## Impact

- Affected code:
  - `Modules/Pos/Services/PosReturnSubmissionService.php`
  - `Modules/Pos/Livewire/PosReturn/PosReturnEditForm.php` if payload shape needs an explicit-resolution marker
  - POS Return feature tests under `Modules/Pos/Tests/Feature/`
- No database schema changes are expected.
- No changes to approval, receiving, settlement, replacement dispatch, stock mutation, payment mutation, or Sales Return execution behavior are expected.
