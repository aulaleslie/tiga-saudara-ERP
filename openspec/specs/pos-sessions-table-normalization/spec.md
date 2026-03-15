# pos-sessions-table-normalization Specification

## Purpose
TBD - created by archiving change fix-pos-sessions-table-alignment. Update Purpose after archive.
## Requirements
### Requirement: Consistent Table Column Structure
The POS sessions index table SHALL always display columns in a fixed, predictable order regardless of session status or active filters. All rows SHALL have the same column structure, eliminating positional misalignment between headers and data cells.

#### Scenario: View table with mixed session statuses
- **WHEN** user views the sessions index without status filter (showing all sessions)
- **THEN** all rows display with identical column positions; column headers align with all data cells

#### Scenario: View table filtered by OPEN status
- **WHEN** user filters to view only OPEN sessions
- **THEN** columns are in the same positions as when viewing all statuses; cells with non-applicable data (e.g., variance) show placeholder `-`

#### Scenario: View table filtered by CLOSED status
- **WHEN** user filters to view only CLOSED sessions
- **THEN** columns are in the same positions as when viewing all statuses; cells with non-applicable data (e.g., transaction count) show placeholder `-`

### Requirement: Status-Aware Column Values
The table columns SHALL display appropriate values or placeholders based on session status, while maintaining static column positions.

#### Scenario: Display transaction count for OPEN session
- **WHEN** displaying an OPEN session row
- **THEN** the "Trx" column shows the transaction count badge

#### Scenario: Display variance for CLOSED session
- **WHEN** displaying a CLOSED/FINALIZED session row
- **THEN** the "Selisih" (variance) column shows the variance amount with appropriate color coding (green for 0, red for non-zero)

#### Scenario: Display placeholders for non-applicable status data
- **WHEN** displaying a session where a metric is not applicable (e.g., transaction count for CLOSED session)
- **THEN** the column cell shows a dash `-` or empty placeholder instead of being hidden

### Requirement: Consistent Cash Column Semantics
The cash column (position 8) SHALL display semantically appropriate values based on session status while keeping the column position consistent.

#### Scenario: Display expected cash for OPEN session
- **WHEN** viewing an OPEN session
- **THEN** column 8 displays "expected_cash_total" with implicit understanding that it represents projected cash

#### Scenario: Display counted cash for CLOSED session
- **WHEN** viewing a CLOSED or FINALIZED session
- **THEN** column 8 displays "counted_cash_total" representing the actual counted amount

### Requirement: Last Activity Display
The last activity column SHALL consistently show the time of most recent session event for OPEN sessions, and a placeholder for closed sessions.

#### Scenario: Display last activity for OPEN session
- **WHEN** viewing an OPEN session
- **THEN** last activity time shows in HH:mm format (e.g., "14:35")

#### Scenario: Display placeholder for closed session activity
- **WHEN** viewing a CLOSED/FINALIZED session
- **THEN** last activity column shows `-` since session is no longer active

