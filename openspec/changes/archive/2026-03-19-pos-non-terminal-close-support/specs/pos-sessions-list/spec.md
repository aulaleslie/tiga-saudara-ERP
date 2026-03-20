## MODIFIED Requirements

### Requirement: Sessions List Table Display
The sessions list view SHALL render a normalized HTML table with consistent column structure across all session statuses and filters. The table structure SHALL NOT vary based on the active status filter or row status values. The table rendering SHALL be null-safe for rows where `terminal_id` is null, including action button metadata and labels.

**Original:** Table columns rendered conditionally, changing position based on status filter and session status, causing misalignment between headers and data.

**Updated:** Table SHALL always maintain fixed columns with header positions matching data cell positions. Column content adapts to status, but column count and order remain constant. Rows without terminal relations SHALL render fallback terminal text and SHALL NOT trigger server-side view errors.

#### Scenario: Verify column alignment when switching between status filters
- **WHEN** user changes the status filter from OPEN to CLOSED to all statuses
- **THEN** column header positions and data cell positions remain aligned throughout all transitions; no visual jumping or shifting of columns occurs

#### Scenario: Verify column structure is identical across rows
- **WHEN** user views a paginated set of sessions containing both OPEN and CLOSED sessions
- **THEN** every row displays cells in identical column positions; no variation in cell count or order between rows

#### Scenario: View sessions with and without last activity data
- **WHEN** user views OPEN sessions that may or may not have recent activity
- **THEN** the last activity column always occupies the same position, showing a time for active sessions and `-` for inactive or closed sessions

#### Scenario: Render action controls when session has no terminal
- **WHEN** a privileged user views `/pos/sessions` and at least one OPEN session has `terminal_id = null`
- **THEN** the page renders successfully without `Attempt to read property` view errors
- **AND** the row shows non-terminal fallback labels in terminal-related action metadata
