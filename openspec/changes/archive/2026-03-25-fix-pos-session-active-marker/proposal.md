## Why

When a POS session is force-closed by an Admin or finalized by a Supervisor, the `active_marker` in the database is not explicitly cleared to `null`. This leaves the session "active" from the application's perspective, causing a bug where users navigating to `/pos/sell` are presented with a finalized (and thus broken) POS UI instead of being prompted to open a new session. This change fixes that bug immediately to ensure the POS flow functions correctly.

## What Changes

- Modify the `PosSessionAdminCloseService` to explicitly set `active_marker => null` when transitioning a session to CLOSED.
- Modify the `PosSessionFinalizeService` to explicitly set `active_marker => null` when transitioning a session to FINALIZED.
- Ensure the `PosSession::activeMarkerForStatus()` method is used to determine the correct marker value during these transitions.

## Capabilities

### New Capabilities
None

### Modified Capabilities
- `pos-session-lifecycle`: Clarify that `active_marker` must be cleared during Admin Force Close and Supervisor Finalization transitions.

## Impact

- **Affected Code**: `Modules\Pos\Services\PosSessionAdminCloseService`, `Modules\Pos\Services\PosSessionFinalizeService`.
- **System**: Prevents finalized/closed sessions from appearing as active, fixing the routing bug on `/pos/sell`.
