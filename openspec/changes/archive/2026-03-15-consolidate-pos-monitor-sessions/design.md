## Context

Currently, POS operations require monitoring active sessions and viewing session history through two separate pages with two different permission gates (`pos.monitor.access` vs `pos.sessions.view`). The monitor page pulls real-time data via `PosSessionMonitorService`, while the sessions page uses a standard paginated query. Both display overlapping session information but through different code paths, creating duplication and maintenance burden.

Users accessing active sessions need real-time metrics (expected cash, transaction counts, safe drop counts, last activity), which are currently only available on the monitor page. The proposal consolidates these into a single sessions page using intelligent filtering.

## Goals / Non-Goals

**Goals:**
- Merge monitor and sessions pages into unified `/pos/sessions` interface
- Active sessions view (`?status=OPEN`) displays real-time monitor metrics alongside historical data
- Sessions page shows transaction counts, expected cash, and "Pengambilan Kas Terkini" (total cash picked up)
- Clicking a session reveals full transaction list and cash event timeline
- Remove `pos.monitor.access` permission dependency; use existing `pos.sessions.view` permission
- Simplify navigation by removing redundant menu item

**Non-Goals:**
- Real-time WebSocket updates (maintain current 30s refresh polling for simplicity)
- Changing permission model beyond removing `pos.monitor.access` requirement
- Refactoring PosSessionMonitorService into multiple services (absorb into query builder)

## Decisions

**1. Query Builder Integration for Monitor Data**
- **Decision**: Enhance `PosSessionController::index()` query to include monitor metrics (withCount relations, threshold checks, cash event timing) instead of calling `PosSessionMonitorService` separately.
- **Rationale**: Consolidates data fetching into one query, reduces API calls, simplifies controller logic. Monitor service becomes internal helper used only during calculation.
- **Alternative Considered**: Keep separate API endpoint (`pos.monitor.sessions`) and call it from the sessions view via JavaScript. Rejected: adds frontend complexity and violates single-source-of-truth principle.

**2. Column Display Logic**
- **Decision**: When `status=OPEN` (active sessions), display monitor columns; when `status=CLOSED` (historical), hide them and show final cash count + variance.
- **Rationale**: Different use cases need different metrics. Active sessions need real-time metrics; historical needs settlement data.
- **Implementation**: Use PHP-side conditional rendering in `session/index.blade.php` based on `$status` variable.

**3. "Pengambilan Kas Terkini" Field**
- **Decision**: Calculate total cash picked up per session by summing safe drop amounts with direction=OUT and type=CASH_PICKUP.
- **Rationale**: Replaces confusing "Setoran Aman" label with clear terminology. Shows supervisors how much cash has been removed from till.
- **Implementation**: Query relation `cashEvents` filtered by event type and direction, sum amounts.

**4. Transaction Details Integration**
- **Decision**: Enhance existing summary endpoint (`pos.sessions.summary`) to include full transaction list and cash event timeline alongside current summary metrics.
- **Rationale**: Reuses existing endpoint and authorization logic; avoids new routes.
- **Alternative Considered**: Create new dedicated endpoint. Rejected: unnecessary, summary endpoint has proper authorization already.

**5. Route Removal Strategy**
- **Decision**: Delete `/pos/monitor` and `/pos/monitor/sessions` routes; keep PosSessionController unchanged except for method removal.
- **Rationale**: Clean approach, unambiguous intent. Old URL will 404; users bookmark sessions page instead.
- **Alternative Considered**: Redirect `/pos/monitor` to `/pos/sessions?status=OPEN`. Rejected: creates technical debt, better to break cleanly.

## Risks / Trade-offs

| Risk | Mitigation |
|------|-----------|
| **Pagination impact**: Monitor query adds relations and counts; pagination on large session datasets could slow down. | Query will be optimized with eager loading and proper indexes. Monitor view originally showed only active sessions, so filtering on `status=OPEN` reduces dataset before pagination. |
| **Permission model gap**: Removing `pos.monitor.access` means users with only that permission lose access. | Unlikely in practice—roles that need monitoring usually have `pos.sessions.view`. Audit existing role assignments before deploy. |
| **Translation/label debt**: "Pengambilan Kas Terkini" is new label; other UI text references "Setoran Aman". | Update all references during this work to ensure consistency. |
| **Summary endpoint load**: If transaction list is large, summary endpoint response could grow significantly. | Implement pagination or limits on transaction list (e.g., last 50 transactions). Assess in testing. |

## Migration Plan

1. **Enhance index() query** with monitor relations and calculations
2. **Update session/index.blade.php** with new columns and conditional display
3. **Remove routes** (lines 26-29 in Pos/Routes/web.php)
4. **Remove controller methods** (`monitor()`, `monitorApi()`)
5. **Remove monitor view** file
6. **Update menu.blade.php** to remove monitor link
7. **Test**: Verify `?status=OPEN` filter displays correct metrics; verify summary endpoint includes transaction list

**Rollback**: Revert commits, restore monitor view and routes, restore controller methods. No database migrations needed.

## Open Questions

- Should transaction list in summary be paginated? If so, what limit (default 50)?
- Do we need to retain `PosSessionMonitorService` for backward compatibility or fully remove it?
- Any existing API consumers relying on `pos.monitor.sessions` endpoint that need migration notice?
