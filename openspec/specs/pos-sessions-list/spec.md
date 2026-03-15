# pos-sessions-list Specification

## Purpose
TBD - created by archiving change fix-pos-sessions-table-alignment. Update Purpose after archive.
## Requirements
### Requirement: Sessions List Table Display
The sessions list view SHALL render a normalized HTML table with consistent column structure across all session statuses and filters. The table structure SHALL NOT vary based on the active status filter or row status values.

**Original:** Table columns rendered conditionally, changing position based on status filter and session status, causing misalignment between headers and data.

**Updated:** Table SHALL always maintain fixed columns with header positions matching data cell positions. Column content adapts to status, but column count and order remain constant.

#### Scenario: Verify column alignment when switching between status filters
- **WHEN** user changes the status filter from OPEN to CLOSED to all statuses
- **THEN** column header positions and data cell positions remain aligned throughout all transitions; no visual jumping or shifting of columns occurs

#### Scenario: Verify column structure is identical across rows
- **WHEN** user views a paginated set of sessions containing both OPEN and CLOSED sessions
- **THEN** every row displays cells in identical column positions; no variation in cell count or order between rows

#### Scenario: View sessions with and without last activity data
- **WHEN** user views OPEN sessions that may or may not have recent activity
- **THEN** the last activity column always occupies the same position, showing a time for active sessions and `-` for inactive or closed sessions

### Requirement: Responsive Table Layout
The sessions list table SHALL use fixed column widths or controlled layout to prevent cell shifting when switching between filter views.

#### Scenario: Prevent column width reflow on filter change
- **WHEN** user switches status filters or views pages with different data values
- **THEN** column widths remain consistent; no text reflow or cell repositioning occurs

