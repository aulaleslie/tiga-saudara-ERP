## Why

Currently, POS session uniqueness is scoped strictly to the current `setting_id`. This allows a single cashier user to accidentally open multiple concurrent sessions across different settings, potentially causing multi-tenant inventory deductions, reporting issues, or incorrect cash reconciliation in the wrong physical location. Enforcing a global "1 active session per user" lock ensures cash reconciliation stays firmly in the physical setting where the session was originally opened and inherently avoids cross-setting bugs. 

## What Changes

- Modify database unique constraint on POS sessions for active sessions to lock globally per cashier instead of per setting.
- Update `PosSessionLifecycleService` so that when a session opening is requested, it globally checks for any active session by this user.
- Add a domain exception to block opening a session if an active one exists in another setting.
- Update `PosSessionController@create` and POS open session view to proactively check and display an error banner, disabling the "Buka Sesi" button if an active session exists in another setting. 

## Capabilities

### New Capabilities

### Modified Capabilities
- `pos-session-management`: Changing the requirement from "1 open session per user per setting" to "1 open session per user across all settings globally".

## Impact

- **Database**: Migration required to modify index on `pos_sessions` from `pos_sessions_user_active_unique` (which includes `setting_id`) to a new index only strictly locking `cashier_user_id` alongside `active_marker`.
- **Services**: `PosSessionLifecycleService` logic for `openSession`. 
- **Controllers/Views**: `PosSessionController@create` and `Modules/Pos/Resources/views/session/open.blade.php`.
