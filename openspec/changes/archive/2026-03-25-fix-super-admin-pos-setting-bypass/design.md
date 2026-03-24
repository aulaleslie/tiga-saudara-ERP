## Context

The POS module implements a security check in Plusieurs services to ensure that the user performing an action is explicitly assigned to the business setting (location) associated with the terminal or session. This is checked against the `user_setting` pivot table.

While this is correct for standard operators and supervisors, it blocks "Super Admin" users who are expected to have global access and may not be explicitly assigned to every business location in a multi-outlet setup.

## Goals / Non-Goals

**Goals:**
- Allow users with the `Super Admin` role to bypass the `user_setting` assignment check in POS services.
- Maintain existing security for all other roles.
- Implement the bypass consistently across all affected services.

**Non-Goals:**
- Removing the `user_setting` table or its general purpose.
- Changing how `Super Admin` is defined (it remains a role name check).
- Modifying other authorization checks (like `pos.sessions.close` permission) which are already bypassed for Super Admin via `Gate::before`.

## Decisions

### 1. Load User Model in Services for Role Check
Since most affected services currently only receive a `userId` (int), we will need to load the `User` model to check for the `Super Admin` role using `$user->hasRole('Super Admin')`.

**Rationale:**
- The `hasRole` method from Spatie's `HasRoles` trait is the standard way roles are checked in this project.
- Loading a user by ID is a fast indexed query.

**Alternatives:**
- Direct DB query on `model_has_roles`: Faster than loading the full model but less maintainable and duplicates logic from `HasRoles` trait.
- Passing the `User` object into the service: Preferred if the caller already has it, but would require changing many service signatures and their callers. We will stick to loading it within the service for minimal external impact.

## Risks / Trade-offs

- **[Risk]** → Performance overhead of loading the User model.
- **[Mitigation]** → Loading by ID is fast, and these are administrative actions (closing/finalizing sessions) which are not high-frequency throughput paths.

- **[Risk]** → Inconsistent role names.
- **[Mitigation]** → We will use the literal string 'Super Admin' which is established as the global admin role in `AuthServiceProvider`.
