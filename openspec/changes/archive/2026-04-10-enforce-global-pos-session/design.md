## Context

POS Sessions represent a cashier's working shift. Historically, these sessions were unique per (`setting_id`, `cashier_user_id`, `active_marker`), meaning one user could have multiple open sessions concurrently as long as they were in different physical settings.

This creates several business operational issues:
1. Multi-tenant inventory deductions might be incorrectly attributed.
2. Cash handling and balancing metrics become skewed across physical locations. 

The new approach ensures a 1-to-1 global lock on active sessions per user using a database constraint and service-layer global checks.

## Goals / Non-Goals

**Goals:**
- Enforce that a user can only have one `active` POS Session across the entire application, irrespective of `setting_id`.
- Ensure users are given clear feedback when they attempt to open an overlapping session in a different setting.
- Provide a smooth UI block at the start of the `session/open` form if a cross-setting session is ongoing.

**Non-Goals:**
- Allowing remote closures of sessions from other settings. We strictly require the user to switch to the original location to do the cash closing and counting to maintain operational integrity.

## Decisions

**1. Unique Index on State Machine**
We will remove the existing `pos_sessions_user_active_unique` and introduce a simpler `pos_sessions_global_active_user_unique` index on `(cashier_user_id, active_marker)`. This ensures absolutely zero race conditions where a user can bypass the service layer using parallel HTTP requests to two different settings.

**2. Service Layer Relaxation of `setting_id` Lookup**
In `PosSessionLifecycleService::openSession`, the pre-emptive check will be broadened. We will query `where('cashier_user_id', $cashierUserId)` without the `setting_id` clause. Upon retrieval:
- If `activeSession->setting_id === $currentSettingId`, it handles the normal session resumption.
- If `activeSession->setting_id !== $currentSettingId`, it throws a standard DomainException with a custom message that refers to the setting name. The query must load the `setting` relationship so we can print the setting name gracefully in the error message.

**3. Controller Interception**
To prevent users from navigating to the form blindly, `PosSessionController@create` will check if an active session exists in another setting. If it does, we pass an `activeSessionInOtherSetting` variable to `session/open.blade.php`. The template will detect this variable and render a block message instead of the regular input form. 

## Risks / Trade-offs

- **Risk:** The database migration could fail if a user actually has multiple active sessions right now. 
  - **Mitigation:** The active session list is small and highly volatile. Applying this migration off-hours inherently minimizes risk, or we can include a small cleanup script inside the migration to forcefully mark duplicate sessions as closed if found. For this specific change, we assume normal migration behavior.

- **Trade-off:** The user has to manually switch branches (using the header branch switcher) to close the orphaned session. 
  - **Rationale:** This is preferred for cash safety. Closing a session requires declaring cash drops and expected totals which the user shouldn't do without being in the correct context for that physical and logical setting.
