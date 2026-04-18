## Requirements

### Requirement: Bundle dispatch tax context inherits parent sale line
The system SHALL derive dispatch tax context for each bundle component from its parent `sale_details.sale_detail_id -> sale_details.tax_id` and MUST use that inherited value when building dispatch composite keys.

#### Scenario: Taxed parent sale line with bundle component
- **WHEN** a sale detail has a non-null `tax_id` and dispatch aggregation includes its bundle components
- **THEN** each bundle component SHALL be keyed as taxed and resolved against the tax stock bucket.

#### Scenario: Non-tax parent sale line with bundle component
- **WHEN** a sale detail has a null `tax_id` and dispatch aggregation includes its bundle components
- **THEN** each bundle component SHALL be keyed as non-tax and resolved against the non-tax stock bucket.

### Requirement: Dispatch page stock display and submission validation remain tax-consistent
The system MUST use the same inherited bundle tax context for both dispatch-page stock display and server-side dispatch validation.

#### Scenario: Stock display and validation alignment for bundle component
- **WHEN** a user selects a location for a non-serial bundle component on the dispatch page
- **THEN** the displayed stock quantity and server-side stock validation SHALL evaluate the same tax bucket derived from the component's parent sale line.
