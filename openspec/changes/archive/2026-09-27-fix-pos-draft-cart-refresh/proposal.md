# Proposal

## Why

After a POS user successfully saves a cart as a draft, the backend clears the session cart but the sell page can continue showing stale cart state or an enabled draft-save action. The successful save boundary needs to return and render an authoritative empty-cart state so the shell is reliably ready for the next customer.

## What Changes

- Include the post-save empty cart snapshot in the successful save-and-new response.
- Render that returned snapshot directly in the POS sell page instead of relying on a follow-up cart GET request.
- Keep the draft-save button and related cart controls derived from the rendered cart state after request cleanup.
- Prevent pending note-response UI work from repainting state from the previous transaction context after a successful draft save.
- Normalize the pre-existing `pos-sell-save-new` main spec structure without changing its behavior so this delta can be archived successfully.
- Add focused automated regression coverage and document a human browser verification; no full-suite verification is required for this change.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `pos-sell-save-new`: Clarify that a successful save-and-new response establishes an authoritative empty-cart UI state and leaves the POS shell controls ready for a new transaction.

## Impact

- POS draft-save JSON response contract in `Modules/Pos/Http/Controllers/PosTransactionController.php`.
- POS sell-page save-and-new client flow in `Modules/Pos/Resources/views/sell.blade.php`.
- Focused POS save-and-new feature and rendered-source regression tests.
- Behavior-preserving structural correction of `openspec/specs/pos-sell-save-new/spec.md` required for future archive compatibility.
- No database schema, dependency, permission, or breaking API changes.
