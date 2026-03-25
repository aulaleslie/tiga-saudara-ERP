## Context

Currently, the POS session opening flow has conditional logic based on the `pos.sessions.require-terminal` permission. When a user has this permission, the terminal field becomes mandatory during validation and is marked as required in the UI. The validation rules in `StorePosSessionOpenRequest` and the UI in `open.blade.php` both check `$requiresTerminalSelection` to decide behavior.

The change requires making terminal selection completely optional for all users, removing the permission-based conditional logic entirely.

## Goals / Non-Goals

**Goals:**
- Make terminal selection optional for all users opening a POS session
- Simplify the session opening form to always show terminal as an optional field
- Update validation to allow null `terminal_id` for all users
- Ensure opening float is only required when a terminal is actually selected
- Deprecate or remove the `pos.sessions.require-terminal` permission check

**Non-Goals:**
- Change the permission system itself or how other permissions work
- Alter session lifecycle logic (sessions can still have a terminal if one is selected)
- Modify terminal policy enforcement for other POS workflows
- Remove the permission record from the database (it will just become unused)

## Decisions

**Decision 1: Remove permission-based conditional validation**
- **Approach**: Always treat `terminal_id` as nullable in validation rules, regardless of user permissions
- **Rationale**: The requirement is that all users should be able to open sessions without terminal selection. Having permission-based conditional logic contradicts this requirement.
- **Alternative considered**: Keep the permission check but flip it (require terminal only if user LACKS the permission) — rejected because it adds unnecessary complexity

**Decision 2: Simplify the UI to a single form**
- **Approach**: Remove the `@if($requiresTerminalSelection)` branch entirely and show a single, unified form where terminal is always optional
- **Rationale**: With terminal always optional, there's no need for conditional UI branches. A single form is clearer and simpler.
- **Alternative considered**: Keep both branches but make the "required" branch show terminal as optional — rejected because it leaves dead code paths

**Decision 3: Opening float conditional behavior based on terminal presence**
- **Approach**: Keep the float field optional by default, but require it only when a terminal is selected (via JavaScript/client-side logic)
- **Rationale**: Float makes sense only when a terminal is actively being used; if no terminal is selected, there's no need for opening float. This aligns with the session lifecycle where float is terminal-specific.
- **Alternative considered**: Always require opening float — rejected because it doesn't make sense without a terminal

**Decision 4: Remove requiresTerminalSelection from PosRolePolicyService**
- **Approach**: Delete the `requiresTerminalSelection()` method and the corresponding capability flag from `capabilityFlags()`
- **Rationale**: Since terminal is never mandatory, this method serves no purpose. Removing it prevents confusion and reduces surface area.
- **Alternative considered**: Keep the method but always return false — rejected because it's cleaner to remove dead code

## Risks / Trade-offs

**Risk: Backward compatibility with custom code**
- **Mitigation**: The changes are isolated to the session opening form. Any custom code relying on `requiresTerminalSelection()` will break at call time, making the break obvious and traceable. No silent failures.

**Risk: Tests expecting permission enforcement**
- **Mitigation**: Update test expectations in `POSSessionRoleTerminalAllocationTest.php` and `POSRoleMatrixEnforcementTest.php` to reflect that terminal is always optional.

**Risk: Users accidentally opening sessions without terminal when they should select one**
- **Mitigation**: This is mitigated by the fact that the business requirement explicitly states terminal should be optional for all users. If terminal selection is needed for a specific role in the future, that's a new requirement that should be addressed separately.

## Migration Plan

1. **Code changes** (backward compatible):
   - Update validation rules to always treat terminal as nullable
   - Simplify the Blade view to remove conditional branches
   - Remove permission checks from the controller
   - Remove the `requiresTerminalSelection()` method

2. **Testing**:
   - Update existing tests to expect optional terminal behavior
   - Add tests confirming all user types can open sessions without terminal

3. **Deployment**:
   - No database migrations needed (permission becomes unused but stays in db)
   - No data cleanup needed
   - Rollback: Revert commits and redeploy

## Open Questions

- Should we send a deprecation notice in logs when the `pos.sessions.require-terminal` permission is queried? (Deferred decision—likely no, since it will just go unused)
