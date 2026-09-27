## MODIFIED Requirements

### Requirement: Explicit global sales payment filter application
The system SHALL present the global sales-payment multi-business selector, document-date range, and due-date range as one responsive filter panel below the existing summary cards, with explicit application, reset, and applied-state feedback.

#### Scenario: User applies combined sales filters
- **WHEN** an authorized user selects zero or more businesses and/or enters one or both `Tanggal Dokumen` or `Tanggal Jatuh Tempo` boundaries and selects `Terapkan Filter`
- **THEN** the global sales table and summary cards refresh using the applied values
- **AND** the table contains only otherwise eligible sales whose `setting_id` is selected when any businesses were selected, whose `date` satisfies every supplied inclusive document-date boundary, and whose `due_date` satisfies every supplied inclusive due-date boundary
- **AND** fully paid sales remain eligible to appear when they meet the applied due-date range and other global-list criteria
- **AND** the workspace visibly identifies each active filter group or its filtered result state

#### Scenario: Draft values do not change the sales list prematurely
- **WHEN** an authorized user changes a business, document-date, or due-date control before selecting `Terapkan Filter`
- **THEN** the existing table, summaries, and applied-state feedback remain based on the previously applied values

#### Scenario: User supplies an incomplete or reversed sales date range
- **WHEN** an authorized user applies only one boundary for either date range or enters a from date later than the to date for the same range
- **THEN** the system applies the supplied single boundary or normalizes that range's two boundaries into chronological order
- **AND** the visible controls and applied-state feedback reflect the effective range

#### Scenario: Sale without a due date is filtered by a supplied due-date boundary
- **WHEN** an authorized user applies either due-date boundary
- **THEN** an otherwise eligible sale without a due date does not appear in the filtered table or contribute to filtered summary-card totals

#### Scenario: User resets sales filters
- **WHEN** an authorized user selects `Reset semua filter` in the global sales-payment filter panel
- **THEN** selected businesses, document-date boundaries, and due-date boundaries are cleared from draft and applied state
- **AND** the business selector visibly returns to the all-businesses state, both date-range controls visibly become empty, and applied-filter feedback disappears
- **AND** the table and summaries return to their unfiltered eligible global results

### Requirement: Durable global sales summary-card selection
The system SHALL preserve the visible selected summary-card state while global sales filters or summaries refresh.

#### Scenario: Selected card remains visible after filter application
- **WHEN** an authorized user selects an outstanding, overdue, or paid summary card and then applies selected businesses, document-date boundaries, or due-date boundaries
- **THEN** the same card remains visibly selected
- **AND** its payment-state condition remains composed with the applied filters, text search, and eligible-sales constraints

### Requirement: Global sales payment state survives page refresh
The system SHALL restore the full applied multi-business, document-date, due-date, and summary-card selection state of the global sales-payment workspace from its shareable URL, so that after a page refresh the table results, summary-card totals, visible filter controls, and card highlight all match the restored state.

#### Scenario: Refresh with applied filters and card selection
- **WHEN** an authorized user refreshes a global sales-payment URL that encodes selected businesses, document-date boundaries, due-date boundaries, and a selected summary card
- **THEN** the table SHALL show only sales satisfying every applied filter and the selected card's payment-state condition
- **AND** the summary cards SHALL compute their totals using the same applied filters
- **AND** the filter controls and previously selected card SHALL remain visibly restored

#### Scenario: Refresh with no encoded state
- **WHEN** an authorized user loads the global sales-payment page without filter or selection parameters
- **THEN** the table and summary cards SHALL show the unfiltered eligible global results with no card selected and the controls in their all-businesses/empty-date state

### Requirement: Global sales filter controls use the application form styling
The system SHALL render the global sales-payment multi-business selector, document-date inputs, due-date inputs, and per-page selector with form styles supported by the application's loaded CSS framework, with explicit `Dari` and `Hingga` labels for each date range.

#### Scenario: Responsive filter controls render clearly
- **WHEN** an authorized user views the global sales-payment filter panel on a wide or narrow viewport
- **THEN** business selection and each date range are visibly grouped and distinguishable
- **AND** the panel retains the existing summary-card placement above it
- **AND** the reset control has a text label that communicates it clears all filters
