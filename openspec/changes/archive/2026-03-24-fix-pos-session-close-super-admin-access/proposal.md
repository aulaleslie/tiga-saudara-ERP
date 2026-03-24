## Why

Super Admin users cannot close POS sessions due to two blocking issues:
1. The `SuperUserSeeder` creates the "Super Admin" role but never assigns any permissions to it, causing permission checks to fail
2. Route parameters for session endpoints are passed as strings, not integers, causing PHP type errors when controllers expect `int` types

This breaks a core admin workflow and prevents system administrators from managing POS terminals.

## What Changes

- **Seeder**: Modify `SuperUserSeeder` to assign all permissions to the "Super Admin" role during initialization
- **Gate**: Add a gate bypass in `AuthServiceProvider` that allows Super Admin to bypass permission checks on all abilities
- **Routes**: Add `->whereNumber()` constraints to all session-related routes to ensure route parameters are cast to integers before reaching controllers
- **Affected routes**: `/pos/sessions/{session}/summary`, `/pos/sessions/{session}/safe-drops`, `/pos/sessions/{session}/pickup`, `/pos/sessions/{session}/close`, `/pos/sessions/{session}/finalize`

## Capabilities

### New Capabilities
None - this is a bug fix for existing POS session management capabilities.

### Modified Capabilities
- `pos-session-closing`: Fixing permission authorization and route parameter type handling to allow Super Admin to close POS sessions

## Impact

- **Code**: `SuperUserSeeder`, `AuthServiceProvider`, `Modules/Pos/Routes/web.php`
- **Permissions**: All existing permissions are now assigned to Super Admin role
- **APIs**: Session close, finalize, pickup, safe-drop, and summary endpoints now correctly handle integer IDs
- **Users**: Super Admin now has unrestricted access to all POS operations; other users unaffected
