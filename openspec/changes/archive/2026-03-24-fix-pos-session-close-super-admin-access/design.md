## Context

The POS session management system requires role-based access control via Laravel's authorization gates. The application uses Spatie's `laravel-permission` package with role-based permission checking.

Currently, two issues prevent Super Admin from closing POS sessions:

1. **Permission Assignment Gap**: The `SuperUserSeeder` creates the "Super Admin" role but never assigns permissions to it. When a Super Admin attempts to close a session, the controller calls `$user->can('pos.sessions.close-admin')`, which fails because the role has zero assigned permissions.

2. **Route Parameter Type Mismatch**: POS session routes pass route parameters as strings by default. Controllers expect integer types (`int $session`). PHP 8.0+ enforces strict typing, causing `TypeError` when a string is passed to an `int`-typed parameter.

The existing `AuthServiceProvider` already has a `Gate::before()` callback (lines 28-32) that returns `true` for Super Admin users, but it never executes because the permission check fails at the controller level before the gate is evaluated.

## Goals / Non-Goals

**Goals:**
- Allow Super Admin users to close POS sessions without permission errors
- Ensure all session-related routes correctly handle integer IDs
- Establish Super Admin as having unrestricted access to all application permissions
- Maintain backward compatibility with existing non-Super Admin workflows

**Non-Goals:**
- Create new permissions or modify permission definitions
- Change the permission architecture for regular users
- Modify POS session business logic (only authorization layer)
- Add additional approval workflows or validation steps

## Decisions

### Decision 1: Assign All Permissions to Super Admin Role in Seeder

**What:** Modify `SuperUserSeeder` to sync all available permissions to the "Super Admin" role during initialization.

**Why:** The cleanest approach is to make the role assignment itself complete. This ensures:
- Consistency with the "Admin" role (which gets all permissions via `PermissionsTableSeeder`)
- Super Admin has an explicit, auditable permission grant (visible in the `role_has_permissions` table)
- The existing gate bypass in `AuthServiceProvider` works as intended (defense in depth)
- No performance impact (seeder runs once, at deployment time)

**Alternatives Considered:**
- Only add the missing `pos.sessions.close-admin` permission → Too narrow; Super Admin should have all permissions
- Create a separate permission cache for Super Admin → Adds complexity without benefit
- Rely only on the gate bypass → Less explicit; harder to audit what permissions Super Admin has

**Implementation:** In `SuperUserSeeder`, after assigning the role, fetch all permissions and sync them to the Super Admin role.

---

### Decision 2: Use Route Constraints for Type Safety

**What:** Add `->whereNumber('session')` constraints to all affected routes.

**Why:**
- Laravel route constraints are the standard way to enforce parameter types at the routing layer
- Casting happens before the controller receives the parameter, preventing PHP type errors
- No performance cost (routing happens before controller instantiation)
- Consistent with existing patterns in the same routes file (e.g., lines 68, 71)
- Makes route contracts explicit in the routes file

**Alternatives Considered:**
- Remove type hints from controller parameters → Reduces type safety and IDE support
- Cast in controller methods → Works, but violates separation of concerns
- Use implicit route model binding → Overkill for simple integer IDs

**Implementation:** Add `->whereNumber('session')` to each route that passes `{session}` as a parameter.

---

### Decision 3: Keep Existing Gate Bypass as Defense in Depth

**What:** Leave the existing `Gate::before()` callback in `AuthServiceProvider` unchanged (lines 28-32).

**Why:**
- It provides an additional layer of access control
- Ensures even if permissions get out of sync, Super Admin can still perform actions
- Follows principle of defense in depth
- Already working and tested

**No Changes:** This callback is already correct.

---

## Risks / Trade-offs

| Risk | Mitigation |
|------|-----------|
| **Super Admin has unrestricted access** → Could be a security concern if credentials are compromised | Restrict Super Admin credentials; use strong passwords and MFA. Document that Super Admin role should only be assigned to trusted admins. |
| **Seeder assigns all future permissions to Super Admin automatically** → If a new permission is added, Super Admin gets it without explicit review | Update documentation for permission additions; consider requiring explicit approval for sensitive permissions. |
| **Route constraints might fail silently** → Invalid route parameter formats (e.g., `{session}=abc`) will no longer match the route | This is intentional and good (fail-secure). Test that invalid IDs return 404, not 500. |
| **Multiple places now grant Super Admin access** (seeder + gate) → Complexity in understanding access control flow | Document this in comments; the gate is defensive, the seeder is primary. |

## Migration Plan

**Deployment:**
1. Deploy code changes (seeder modification, route constraints)
2. Run `php artisan db:seed --class=SuperUserSeeder` to sync permissions
3. Clear any permission caches: `php artisan cache:clear` (if permission caching is enabled)

**Rollback:**
1. Revert code changes
2. Run `php artisan db:seed --class=SuperUserSeeder` again (will restore old state with no permissions)
3. Clear caches

**Verification:**
- Super Admin can close a POS session without errors
- Non-Super Admin users can still only close sessions according to their assigned permissions
- Invalid session IDs return 404 (not 500 type errors)
