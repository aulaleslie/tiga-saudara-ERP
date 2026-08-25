## ADDED Requirements

### Requirement: Quick-add product creation SHALL initialize prices for every business
When the shared product quick-add flow creates a product from Purchase or Sales, the system SHALL create one `product_prices` row for every business setting that exists at creation time. Every row SHALL receive the same submitted initial purchase and sale pricing values, subject to the product's purchased and sold flags, while normal product edits SHALL remain scoped to the current business.

#### Scenario: Purchase quick-add initializes every business price row
- **WHEN** a user creates a product through Purchase quick-add with valid pricing data
- **THEN** the system SHALL create a price row for that product in every existing business
- **AND** each row SHALL contain the same initial values derived from the submitted quick-add data

#### Scenario: Sales quick-add initializes every business price row
- **WHEN** a user creates a product through Sales quick-add with a valid positive sale price
- **THEN** the system SHALL create a price row for that product in every existing business
- **AND** each row SHALL contain the same base and derived tier sale prices

#### Scenario: Normal product edit remains business-scoped
- **WHEN** a user edits an existing product from the normal product edit page
- **THEN** price changes SHALL apply only to the current business
- **AND** price rows for other businesses SHALL remain unchanged
