## MODIFIED Requirements

### Requirement: Status and period filters
The sales report SHALL allow filtering by multiple report-eligible document statuses (`DISPATCHED` and `RETURNED PARTIALLY`) and multiple payment statuses, plus a date range with period presets and a date-basis selector. Ineligible statuses SHALL NOT be offered or accepted and the default report population SHALL be restricted to eligible documents.

#### Scenario: Applying a period preset
- **WHEN** the user selects a preset (today, this week, this month, this year)
- **THEN** the start and end dates are set to that period's bounds

#### Scenario: Multi-status filtering
- **WHEN** one or more eligible document or payment statuses are selected and filters are applied
- **THEN** results include only sales matching any selected document status and any selected payment status

#### Scenario: Ineligible status is unavailable
- **WHEN** the user opens or submits the document-status filter
- **THEN** pre-dispatch and partially dispatched statuses are not accepted as report filters
