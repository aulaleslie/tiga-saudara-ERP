## Why

The POS cart quantity controls are visually inconsistent between serial and non-serial rows, which makes reduce/increase actions harder to scan and use. Quantity-reduction approval controls can also drift into stale UI states until page refresh, so users do not always get a reliable in-row `Periksa`/`Lanjutkan` experience.

## What Changes

- Standardize non-privileged qty controls to one shared structure across all cart rows: `[Reduce/Periksa slot][qty input][+]`, with serial action rendered as a secondary line for serial-required items.
- Refactor duplicated row-render branches so serial and non-serial rows consume a shared qty-control renderer for reduce/periksa/approved states.
- Stabilize quantity-approval state transitions by using one canonical approval-state source per row and refreshing snapshot-backed state after approval checks.
- Ensure `Periksa`/approved controls remain deterministic without requiring full page reload.
- Add regression coverage for: consistent control rendering across row types and stable approval state transition after `Periksa`.

## Capabilities

### New Capabilities
- None.

### Modified Capabilities
- `pos-supervised-cart-actions`: quantity control UI for supervised qty reduction must render with a consistent slot-based layout across row types, and approval state transitions must remain deterministic without page refresh.

## Impact

- Affected UI: `Modules/Pos/Resources/views/sell.blade.php` (cart row templates, qty-control composition, approval button rendering, and check-approval refresh paths).
- Affected tests: POS supervised cart action coverage for quantity-reduction rendering and approval state transitions.
- No backend API contract changes expected; change is focused on frontend rendering consistency and state synchronization.
