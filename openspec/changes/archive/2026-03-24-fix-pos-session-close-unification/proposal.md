## Why

The POS session close functionality currently has two separate endpoints (`/pos/sessions/{id}/close` and `/pos/sessions/{id}/close-admin`), two permissions (`pos.sessions.close` and `pos.sessions.close-admin`), and two UI buttons on the summary page. This creates redundancy, confusion, and inconsistent authorization logic. A unified endpoint with permission-based authorization simplifies the codebase and provides a single, clear path for all session close operations.

## What Changes

- **Consolidate endpoints**: Merge `/pos/sessions/{id}/close-admin` into `/pos/sessions/{id}/close` (remove redundant route)
- **Unified authorization**: Single endpoint handles both standard cashier close and admin force-close based on user permissions
  - `pos.sessions.close`: Can close their own session (standard close flow)
  - `pos.sessions.close-admin`: Can close any session (admin force-close flow)
- **Single UI button**: Replace dual "Tutup Sesi" and "Tutup Sesi (Admin)" buttons with one "Tutup Sesi" button on summary page
- **Simplified modal**: Single close modal handles all cases (backend determines close type)
- **Streamlined controller**: Single `close()` method dispatches to appropriate service based on authorization

## Capabilities

### Modified Capabilities
- `pos-session-close`: Authorization logic expanded to support both standard and admin close in one endpoint
- `pos-admin-force-close`: Behavior consolidated into unified `pos-session-close` endpoint (same outcomes, unified routing)

## Impact

- **Routes**: Remove `pos.sessions.close-admin` route; update `pos.sessions.close` route to handle both close types
- **Controller**: Consolidate `closeFinalize()` and `closeAdmin()` into single `close()` method in `PosSessionController`
- **Views**: Remove `_close-admin-modal.blade.php`; update `summary.blade.php` to show single button
- **JavaScript**: Simplify `pos-session-handlers.js` (remove closeAdminModal initialization)
- **Permissions**: Keep both `pos.sessions.close` and `pos.sessions.close-admin` but use them for authorization logic only
