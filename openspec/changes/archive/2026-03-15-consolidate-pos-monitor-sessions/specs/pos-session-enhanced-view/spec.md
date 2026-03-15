## ADDED Requirements

### Requirement: Unified sessions list with dual-mode display
The system SHALL display POS sessions in a single `/pos/sessions` page that intelligently shows different metrics based on session status filter (active vs. historical).

#### Scenario: View active sessions (real-time monitoring mode)
- **WHEN** user accesses `/pos/sessions?status=OPEN` or visits `/pos/sessions` (all sessions includes active)
- **THEN** system displays active sessions with monitor-specific metrics:
  - Expected Cash Total (current running total in the till)
  - Transaction Count (number of sales/checkouts)
  - Pengambilan Kas Terkini (total cash already picked up via safe drops/pickups)
  - Last Activity timestamp
  - No "Counted Cash" or "Variance" fields (not applicable to open sessions)

#### Scenario: View historical sessions
- **WHEN** user filters by `/pos/sessions?status=CLOSED`
- **THEN** system displays completed sessions with settlement metrics:
  - Counted Cash Total (final amount counted at close)
  - Variance Total (difference between expected and counted)
  - No "Expected Cash" or "Pengambilan Kas Terkini" fields (not applicable to closed sessions)

#### Scenario: View all sessions
- **WHEN** user accesses `/pos/sessions` with no status filter
- **THEN** system displays all sessions (both open and closed) using conditional columns:
  - Open sessions show monitor columns
  - Closed sessions show settlement columns

### Requirement: Session drill-down with transaction history
Clicking a session row SHALL open a details view showing complete transaction information.

#### Scenario: Open session details
- **WHEN** user clicks "Detail Ringkasan" (Summary Detail) on a session row
- **THEN** system displays session summary including:
  - Current metrics (expected cash, transaction count, safe drops, last activity)
  - Full transaction list showing all checkouts/sales completed in this session
  - Cash event timeline showing all safe drops, pickups, and cash movements
  - Timestamps for each transaction and cash event

### Requirement: Pengambilan Kas Terkini displays cash pickup amount
The system SHALL display total amount of cash already picked up from the session.

#### Scenario: Calculate cash picked up
- **WHEN** viewing an active session
- **THEN** system shows "Pengambilan Kas Terkini" as sum of all safe drops and supervisor pickups:
  - Formula: SUM(cash_events.amount WHERE event_type = CASH_PICKUP AND direction = OUT)
  - Updates in real-time as supervisors perform pickups
  - Shows zero if no pickups have occurred

### Requirement: No separate monitor page
The system SHALL remove the dedicated `/pos/monitor` page and consolidate all monitoring into `/pos/sessions`.

#### Scenario: Attempt to access old monitor URL
- **WHEN** user navigates to `/pos/monitor` (old URL)
- **THEN** system returns 404 Not Found (route no longer exists)

#### Scenario: Monitor functionality via sessions filter
- **WHEN** user with `pos.sessions.view` permission filters `/pos/sessions?status=OPEN`
- **THEN** system provides full monitoring capability without requiring `pos.monitor.access` permission
