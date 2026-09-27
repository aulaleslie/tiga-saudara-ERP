## MODIFIED Requirements

### Requirement: Sessions List Table Display
The sessions list view SHALL render a normalized HTML table with consistent column structure across all session statuses and filters. The table structure SHALL NOT vary based on the active status filter or row status values.

**Updated Requirements**:
- The Metrik column SHALL display transaction counts for all session statuses (OPEN, CLOSED, CLOSING, FINALIZED), not conditionally based on status
  - For terminal sessions: CASH_SALE_IN event counts
  - For non-terminal sessions: total PosTransaction record counts
- The Aktivitas Terakhir column SHALL display activity timestamps for all session statuses (OPEN, CLOSED, CLOSING, FINALIZED), not conditionally based on status
  - For terminal sessions: most recent CASH_SALE_IN event timestamp
  - For non-terminal sessions: most recent PosTransaction created_at timestamp
  - Format: HH:mm for OPEN sessions, DD/MM/YYYY HH:mm for CLOSED/CLOSING/FINALIZED sessions
- The column logic SHALL be based on terminal presence (terminal_id), not session status

**Original**: Table columns rendered conditionally based on session status, with Metrik showing counts for OPEN but variance for CLOSED, and Aktivitas Terakhir showing times for OPEN but dashes for CLOSED.

**Updated**: All rows maintain consistent column definitions. Content varies by terminal presence, not status. All statuses display complete metrics and activity information.

#### Scenario: Verify Metrik column displays consistently across statuses
- **WHEN** user changes the status filter from OPEN to CLOSED to all statuses
- **THEN** the Metrik column displays transaction counts for all displayed sessions, regardless of status, with values based on terminal presence

#### Scenario: Verify Aktivitas Terakhir displays for both open and closed
- **WHEN** user views OPEN and CLOSED sessions in the same view
- **THEN** both OPEN and CLOSED rows display activity timestamps in the Aktivitas Terakhir column, formatted appropriately for their status

#### Scenario: Terminal sessions show checkout metrics
- **WHEN** user views a session with a terminal assigned
- **THEN** Metrik displays CASH_SALE_IN count and Aktivitas Terakhir shows most recent cash event timestamp

#### Scenario: Non-terminal sessions show creation metrics
- **WHEN** user views a session without a terminal assigned
- **THEN** Metrik displays total PosTransaction count and Aktivitas Terakhir shows most recent transaction creation timestamp

#### Scenario: Filter consistency
- **WHEN** user applies Aktif (OPEN) filter vs Selesai (CLOSED) filter
- **THEN** column headers and column count remain identical; only row data changes based on filter
