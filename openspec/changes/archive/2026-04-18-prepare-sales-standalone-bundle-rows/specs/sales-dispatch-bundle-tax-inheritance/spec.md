## MODIFIED Requirements

### Requirement: Bundle dispatch tax context resolves parent-first with standalone fallback
The system SHALL derive dispatch tax context for each bundle component from its parent `sale_details.sale_detail_id -> sale_details.tax_id` when a parent sale detail is linked. If `sale_detail_id` is absent, the system MUST resolve dispatch tax context from the bundle row's standalone self context and use that value when building dispatch composite keys.

#### Scenario: Taxed parent sale line with linked bundle component
- **WHEN** a linked bundle row has a parent sale detail with a non-null `tax_id`
- **THEN** the bundle component SHALL be keyed as taxed and resolved against the tax stock bucket

#### Scenario: Non-tax parent sale line with linked bundle component
- **WHEN** a linked bundle row has a parent sale detail with a null `tax_id`
- **THEN** the bundle component SHALL be keyed as non-tax and resolved against the non-tax stock bucket

#### Scenario: Standalone bundle component resolves tax from self context
- **WHEN** a bundle row has a null `sale_detail_id`
- **THEN** dispatch aggregation SHALL derive tax bucket from standalone bundle-row context
- **AND** the component SHALL be keyed without requiring parent tax inheritance

### Requirement: Dispatch page stock display and submission validation remain tax-consistent
The system MUST use the same resolved bundle tax context for both dispatch-page stock display and server-side dispatch validation. Resolution SHALL follow parent-first with standalone fallback precedence.

#### Scenario: Stock display and validation alignment for linked bundle component
- **WHEN** a user selects a location for a non-serial linked bundle component on the dispatch page
- **THEN** displayed stock quantity and validation SHALL evaluate the same tax bucket derived from the parent sale line

#### Scenario: Stock display and validation alignment for standalone bundle component
- **WHEN** a user selects a location for a non-serial standalone bundle component on the dispatch page
- **THEN** displayed stock quantity and validation SHALL evaluate the same tax bucket derived from standalone bundle-row context
