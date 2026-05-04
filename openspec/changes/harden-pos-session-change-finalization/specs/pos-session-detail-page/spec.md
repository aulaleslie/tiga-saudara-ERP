## MODIFIED Requirements

### Requirement: Display cash events timeline with filtering
The detail page SHALL display all cash events in reverse chronological order (newest first). Each event SHALL show event type (OPEN_FLOAT, CASH_SALE_IN, SAFE_DROP_OUT, CHANGE_OUT, CLOSE_COUNT, FINALIZE_COUNT), direction (IN, OUT, NEUTRAL), amount, performer name, approver name (if present), notes, and timestamp. The page SHALL provide filter buttons to toggle visibility by event type, including a `CHANGE_OUT` filter labelled as customer change or `Kembalian`.

#### Scenario: View all cash events
- **WHEN** user loads the detail page
- **THEN** all cash events are displayed in a timeline, sorted by timestamp descending (newest first)

#### Scenario: Filter events by type
- **WHEN** user clicks a filter button for event type (e.g., "SAFE_DROP_OUT")
- **THEN** only events of that type remain visible; other types are hidden
- **AND** the button is highlighted/active to show current filter

#### Scenario: Filter customer change events
- **WHEN** user clicks the `Kembalian` or `CHANGE_OUT` filter button
- **THEN** only `CHANGE_OUT` cash events remain visible
- **AND** those events display as OUT movements with negative cash impact

#### Scenario: Clear filter
- **WHEN** user clicks the "All Events" or active filter button again
- **THEN** all events become visible again

#### Scenario: Event with missing performer or approver
- **WHEN** a cash event has no performer or approver (null FK)
- **THEN** system displays "Unknown" or a placeholder in place of the user name
