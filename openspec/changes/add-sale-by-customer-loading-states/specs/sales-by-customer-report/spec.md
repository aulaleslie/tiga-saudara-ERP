## ADDED Requirements

### Requirement: Penjualan Per Customer shows loading feedback while filtering

The system SHALL show visible loading feedback and block duplicate submission while the Penjualan Per Customer filter apply action is in progress, without affecting unrelated Livewire requests.

#### Scenario: Filter apply shows spinner and blocks the table

- **WHEN** the user triggers `applyFilters` (from the inline Filter button or the drawer's Filter button)
- **THEN** both Filter buttons SHALL show a spinner and be disabled for the duration of the request
- **AND** the report table SHALL be visually dimmed and non-interactive (pointer events blocked) after a short delay, to avoid flicker on fast responses
- **AND** a centered loading indicator SHALL appear above the table while the request is in progress

#### Scenario: Unrelated requests do not trigger filter loading state

- **WHEN** the user paginates, sorts, or performs a customer/category/tag autocomplete search
- **THEN** the table SHALL NOT be dimmed or blocked
- **AND** the Filter buttons SHALL NOT show a spinner

### Requirement: Penjualan Per Customer shows loading feedback while exporting

The system SHALL show visible loading feedback on the Ekspor dropdown trigger while an export action is in progress and SHALL prevent duplicate export submissions, without dimming the report table.

#### Scenario: Export shows spinner on the dropdown trigger

- **WHEN** the user triggers `exportExcel` or `exportCsv`
- **THEN** the Ekspor dropdown trigger button SHALL show a spinner in place of its normal icon and SHALL be disabled
- **AND** both export actions (Excel and CSV) SHALL be disabled for the duration of the request
- **AND** the report table SHALL NOT be dimmed or blocked during export

#### Scenario: Export loading state clears after completion

- **WHEN** an export request finishes (success or failure)
- **THEN** the Ekspor dropdown trigger SHALL return to its normal icon and enabled state
- **AND** both export actions SHALL become enabled again
