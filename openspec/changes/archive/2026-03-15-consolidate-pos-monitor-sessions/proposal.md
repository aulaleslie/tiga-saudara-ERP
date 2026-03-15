## Why

Currently, POS session monitoring and session management are split across two separate pages (`/pos/monitor` and `/pos/sessions`), creating duplicate functionality and UI complexity. Consolidating these into a single sessions page with intelligent filtering will simplify navigation, reduce maintenance overhead, and provide a unified view of both active and historical sessions with enriched transaction details.

## What Changes

- **Remove `/pos/monitor` page and route** - functionality absorbed into `/pos/sessions`
- **Enhance `/pos/sessions` to display monitor-specific metrics** when filtering by `?status=OPEN` (active sessions view)
- **Add transaction details integration** - clicking a session shows full transaction list (checkouts/sales) plus cash event timeline
- **Rename display field** - "Setoran Aman" → "Pengambilan Kas Terkini" to show total cash already picked up in the session
- **Remove menu item** pointing to monitor page; sessions page now serves both purposes
- **Remove monitor controller methods** - `monitor()`, `monitorApi()`, and integrate monitor data into the main `index()` query

## Capabilities

### New Capabilities
- `pos-session-enhanced-view`: Unified view displaying both historical and active POS sessions with enriched metrics (expected cash, transaction counts, last activity, cash pickups)
- `pos-session-transaction-details`: Drill-down capability showing full transaction list and cash event timeline for a selected session

### Modified Capabilities
- `pos-session-lifecycle`: The session view now integrates real-time monitoring without requiring a separate monitor page; the `?status=OPEN` filter provides the active sessions dashboard

## Impact

- **Routes**: Remove `pos.monitor.index` and `pos.monitor.sessions` routes
- **Controller**: PosSessionController - remove `monitor()` and `monitorApi()` methods; enhance `index()` method
- **Views**: Remove `monitor/index.blade.php`; enhance `session/index.blade.php` with new columns
- **Services**: PosSessionMonitorService logic absorbed into query builder
- **Permissions**: Remove dependency on `pos.monitor.access` for monitor functionality (uses `pos.sessions.view`)
- **Menu**: Remove monitor navigation item from `menu.blade.php`
