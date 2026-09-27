## ADDED Requirements

### Requirement: Detail and header report modes

The sales report SHALL support a "detail" mode (one row per sale line) and a "header" mode (one row per sale document), with the selected mode persisted across requests.

#### Scenario: Switching modes

- **WHEN** the user toggles between detail and header mode
- **THEN** the table columns and available sort fields change to match the mode
- **AND** the chosen mode is restored on the next visit within the session

#### Scenario: Invalid mode is normalized

- **WHEN** a request supplies a report mode outside {detail, header}
- **THEN** the report falls back to detail mode

### Requirement: Multi-select searchable filters

The sales report SHALL allow filtering by multiple customers and multiple tags via searchable inputs that show selected items as removable pills.

#### Scenario: Adding and removing a customer filter

- **WHEN** the user searches a customer name and selects a result
- **THEN** the customer is added as a pill and results are restricted to selected customers when filters are applied
- **AND** removing the pill drops that customer from the filter

#### Scenario: Search requires a minimum query length

- **WHEN** the search term is shorter than 2 characters
- **THEN** no options are suggested

### Requirement: Status and period filters

The sales report SHALL allow filtering by multiple document statuses (dispatched family) and multiple payment statuses, plus a date range with period presets and a date-basis selector.

#### Scenario: Applying a period preset

- **WHEN** the user selects a preset (today, this week, this month, this year)
- **THEN** the start and end dates are set to that period's bounds

#### Scenario: Multi-status filtering

- **WHEN** one or more document or payment statuses are selected and filters are applied
- **THEN** results include only sales matching any selected document status and any selected payment status

### Requirement: Sortable columns

The sales report SHALL allow sorting by columns appropriate to the active mode, toggling ascending/descending on repeated selection.

#### Scenario: Sorting resets to a safe default across modes

- **WHEN** the active sort field is not valid for the current mode
- **THEN** the report falls back to sorting by date descending

### Requirement: Snapshot-validated export

The sales report SHALL export the currently filtered result set to Excel or CSV only when the applied filters match a snapshot taken at the last Filter action.

#### Scenario: Export after filtering

- **WHEN** the user has applied filters and the snapshot is still valid
- **THEN** an Excel or CSV file of the filtered, sorted results is downloaded

#### Scenario: Export blocked after filter drift

- **WHEN** filters were changed after the last Filter action without re-applying
- **THEN** export is refused with a message asking the user to apply filters again

### Requirement: Setting-scoped and global variants

The sales report SHALL run scoped to the current setting by default and SHALL support a global variant that spans all settings when accessed via the global route.

#### Scenario: Global mode spans settings

- **WHEN** the report is opened in global mode
- **THEN** results are not restricted to the current `setting_id`
