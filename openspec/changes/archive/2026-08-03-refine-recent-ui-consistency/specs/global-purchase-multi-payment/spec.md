## ADDED Requirements

### Requirement: Global purchase payment state survives page refresh
The system SHALL restore the full applied filter and summary-card selection state of the global purchase-payment workspace from its shareable URL, so that after a page refresh the table results, summary-card totals, and visible card highlight all match the restored state.

#### Scenario: Refresh with applied filters and card selection
- **WHEN** an authorized user refreshes a global purchase-payment URL that encodes an applied business filter, document-date boundaries, and a selected summary card
- **THEN** the table SHALL show only purchases satisfying both the applied filters and the selected card's payment-state condition
- **AND** the summary cards SHALL compute their totals using the same applied filters
- **AND** the previously selected card SHALL remain visibly selected

#### Scenario: Refresh with no encoded state
- **WHEN** an authorized user loads the global purchase-payment page without filter or selection parameters
- **THEN** the table and summary cards SHALL show the unfiltered eligible global results with no card selected

### Requirement: Global purchase filter controls use the application form styling
The system SHALL render the global purchase-payment filter controls (business selector, document-date inputs, per-page selector) using form styles supported by the application's loaded CSS framework so that all controls appear visually consistent with the rest of the application.

#### Scenario: Business selector renders styled
- **WHEN** an authorized user views the global purchase-payment filter panel
- **THEN** the business dropdown and per-page selector SHALL render with the application's standard select styling rather than unstyled browser defaults
