## MODIFIED Requirements

### Requirement: Delivery rows are sourced from approved dispatches
The report SHALL calculate delivered or completed-work quantities from existing `dispatches` and `dispatch_details` records, using only approved dispatches and filtering by `dispatches.dispatch_date`. Approved non-stock dispatch details SHALL be included as completed-work acknowledgements even though they create no inventory movement.

#### Scenario: Approved stock dispatch included
- **WHEN** an approved stock-managed dispatch has a dispatch date inside the selected report period
- **THEN** its dispatch detail quantities are included in the report

#### Scenario: Approved non-stock work included
- **WHEN** an approved non-stock dispatch acknowledgement has a dispatch date inside the selected report period
- **THEN** its completed-work quantity is included in the report
- **AND** inclusion SHALL NOT imply that inventory movement occurred

#### Scenario: Pending and rejected dispatches excluded
- **WHEN** a pending or rejected dispatch has a dispatch date inside the selected report period
- **THEN** its dispatch detail quantities are not included in the report

#### Scenario: Sale date does not control inclusion
- **WHEN** a Sale date is outside the selected period but its approved dispatch date is inside the selected period
- **THEN** the dispatch detail quantities are included in the report

