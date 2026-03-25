## Why

Currently, users with `pos.sessions.require-terminal` permission see the terminal field as mandatory when opening a POS session. However, the requirement is that terminal selection should be optional for all users—anyone with POS access should be able to open a session with or without selecting a terminal. This change removes the mandatory terminal requirement and makes the terminal field optional across the board.

## What Changes

- Terminal selection becomes optional for all users opening a POS session (not required by any role)
- Validation rules updated to treat `terminal_id` as always nullable
- Opening float total becomes optional when no terminal is selected
- Form UI simplifies to show terminal as an optional field (no asterisk)
- Backend permission check `pos.sessions.require-terminal` logic is removed or depreciated

## Capabilities

### New Capabilities

- `optional-terminal-session-open`: Users can open a POS session without being required to select a terminal, with opening float optional when no terminal is provided

### Modified Capabilities

- `pos-session-lifecycle`: Existing session opening now allows null terminal_id for all users without requiring specific permissions

## Impact

- **Files affected**:
  - `Modules/Pos/Http/Requests/StorePosSessionOpenRequest.php` (validation rules)
  - `Modules/Pos/Http/Controllers/PosSessionController.php` (remove requiresTerminalSelection logic)
  - `Modules/Pos/Resources/views/session/open.blade.php` (UI simplification)
  - `Modules/Pos/Services/PosRolePolicyService.php` (remove or deprecate requiresTerminalSelection method)

- **APIs changed**: Session opening endpoint behavior (terminal no longer required in validation)
- **Permission changes**: `pos.sessions.require-terminal` permission becomes unused
- **User experience**: Simplified session opening flow for all users
