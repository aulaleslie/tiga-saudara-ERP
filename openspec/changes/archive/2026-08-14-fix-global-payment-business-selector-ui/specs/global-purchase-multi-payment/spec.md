## MODIFIED Requirements

### Requirement: Explicit global purchase payment filter application
The system SHALL present the global purchase-payment business and document-date controls as a single filter panel with explicit application, reset, and applied-state feedback, and SHALL treat business selections as draft values until explicitly applied.

#### Scenario: User applies a purchase document-date filter
- **WHEN** an authorized user selects zero or more businesses and/or enters one or both `Tanggal Dokumen` boundaries and selects `Terapkan Filter`
- **THEN** the global purchase table and summary cards refresh using the applied values
- **AND** the table contains only otherwise eligible purchases whose `setting_id` is selected when any businesses were selected and whose `date` satisfies every supplied inclusive boundary
- **AND** an empty applied business selection continues to include all businesses
- **AND** the workspace visibly identifies the active applied filters or filtered result state

#### Scenario: Draft values do not change the purchase list prematurely
- **WHEN** an authorized user changes the multi-business selector or document-date controls before selecting `Terapkan Filter`
- **THEN** the existing table, summaries, URL state, and applied-state feedback remain based on the previously applied values

#### Scenario: User supplies an incomplete or reversed purchase date range
- **WHEN** an authorized user applies only one date boundary or enters a from date later than the to date
- **THEN** the system applies the supplied single boundary or normalizes the two boundaries into chronological order
- **AND** the visible controls and applied-state feedback reflect the effective range

#### Scenario: User resets purchase filters
- **WHEN** an authorized user selects `Reset semua filter` in the global purchase-payment filter panel
- **THEN** business and document-date filters are cleared from draft and applied state
- **AND** the client-side multi-business selector visibly shows no selected businesses
- **AND** the table and summaries return to their unfiltered eligible global results

### Requirement: Global purchase payment state survives page refresh
The system SHALL restore the full applied filter and summary-card selection state of the global purchase-payment workspace from its shareable URL, so that after a page refresh the table results, summary-card totals, visible filter controls, and card highlight all match the restored state.

#### Scenario: Refresh with applied filters and card selection
- **WHEN** an authorized user refreshes a global purchase-payment URL that encodes applied business selections, document-date boundaries, and a selected summary card
- **THEN** the table SHALL show only purchases satisfying both the applied filters and the selected card's payment-state condition
- **AND** the summary cards SHALL compute their totals using the same applied filters
- **AND** every encoded business SHALL appear selected in the multi-business control
- **AND** the previously selected card SHALL remain visibly selected

#### Scenario: Refresh with no encoded state
- **WHEN** an authorized user loads the global purchase-payment page without filter or selection parameters
- **THEN** the table and summary cards SHALL show the unfiltered eligible global results with no card selected
- **AND** the multi-business control SHALL visibly show no selection to represent all businesses

### Requirement: Global purchase filter controls use the application form styling
The system SHALL render the global purchase-payment filter controls using the application's loaded Select2 and CoreUI-compatible form styles so that all controls appear visually consistent with the Laporan Laba Rugi business selector and the rest of the application.

#### Scenario: Business selector renders as a searchable multi-select
- **WHEN** an authorized user views the global purchase-payment filter panel
- **THEN** the business selector SHALL render as a searchable Select2/CoreUI multi-select rather than an unstyled native multi-select
- **AND** selected businesses SHALL render as individually removable choices
- **AND** the per-page selector and date inputs SHALL retain application-supported form styling

