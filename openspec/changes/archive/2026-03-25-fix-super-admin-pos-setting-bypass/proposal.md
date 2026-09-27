## Why

Super Admin users are currently unable to perform various POS terminal operations (like closing a session or performing a safe drop) if they are not explicitly assigned to the business setting in the `user_setting` pivot table. This contradicts the expected "all-access" behavior of the Super Admin role and prevents administrative intervention when terminal operators are unavailable.

## What Changes

The POS services will be updated to allow users with the `Super Admin` role to bypass the hard requirement of being assigned to a business setting in the `user_setting` table. This bypass will apply to session closing, safe drops, and session finalization.

## Capabilities

### New Capabilities
- `pos-super-admin-setting-bypass`: Core capability for detecting Super Admin role and bypassing setting-based assignment checks across POS services.

### Modified Capabilities
- `pos-session-close`: Update close flow to allow Super Admin bypass for the setting assignment check.
- `pos-admin-force-close`: Update admin force-close flow to allow Super Admin bypass for the setting assignment check.
- `pos-supervisor-cash-finalization`: Update supervisor finalization flow to allow Super Admin bypass for the setting assignment check.

## Impact

The following POS services are affected:
- `PosSessionCloseService`
- `PosSafeDropService`
- `PosSessionAdminCloseService`
- `PosSessionFinalizeService`

No API changes are required, as this is a logic update within the service layer. No breaking changes for existing unprivileged users.
