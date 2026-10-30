# Proposal

## Why

POS terminal sessions currently open from a manually entered cash total without retaining a denomination count. Requiring a physical count by default improves opening cash accountability while allowing explicitly authorized users to enter a nominal total.

## What Changes

- Reuse the existing global enabled cash denominations without changing their configuration or introducing business/terminal overrides.
- Default terminal session opening to quantities of banknotes/coins, with subtotals and a calculated opening total.
- Add `pos.sessions.open.total-only` as an explicit permission to switch to nominal entry; keep denomination mode as the default for authorized users too.
- **BREAKING**: Terminal opening requests containing only a total require the new permission and explicit nominal mode. The legacy terminal `allow_total_only_float_input` flag does not authorize bypass.
- Validate and calculate counts on the server, retain the breakdown and entry mode on the opening cash event, and preserve terminal-free opening behavior.

## Capabilities

### New Capabilities

- `pos-session-opening-cash`: Global denomination-based opening cash capture, permission-controlled nominal entry, and opening cash audit persistence.

### Modified Capabilities

- `optional-terminal-session-open`: Replace terminal opening's manually required total with a positive server-derived denomination total or authorized nominal entry.

## Impact

Affects POS session opening Blade UI, controller, request, lifecycle service, permission registry/assignment surfaces, and focused POS tests. Reuses `Modules/Setting/Entities/CashDenomination` and existing cash-event JSON fields. No historical cash-event backfill, denomination configuration change, close/safe-drop redesign, or new external dependency is planned.
