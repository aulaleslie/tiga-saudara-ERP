## ADDED Requirements

### Requirement: Sales import creates products with canonical unit fields
When Sales import creates a missing product from an imported row, the system SHALL resolve the imported unit into the product's current canonical unit model.

#### Scenario: Sales import creates stock-managed product with base unit
- **WHEN** a Sales import row references a product that does not already exist
- **AND** the row includes an imported unit value that resolves to a unit record
- **THEN** the created product MUST have `base_unit_id` set to the resolved unit ID
- **AND** the created product MUST keep `unit_id` set to the resolved unit ID for legacy compatibility
- **AND** the created product MUST populate `product_unit` from the resolved unit short name or name when available

#### Scenario: Sales import-created product can be edited without changing unit
- **WHEN** a product created by Sales import is opened on the Product edit page
- **AND** the product unit field is locked because the product is stock-managed or has stock
- **AND** the user changes only editable non-unit fields such as prices
- **THEN** the product update MUST pass unit validation using the product's canonical `base_unit_id`

### Requirement: Purchase import creates products with canonical unit fields
When Purchase import creates a missing product from an imported row, the system SHALL resolve the imported unit into the product's current canonical unit model.

#### Scenario: Purchase import creates stock-managed product with base unit
- **WHEN** a Purchase import row references a product that does not already exist
- **AND** the row includes an imported unit value that resolves to a unit record
- **THEN** the created product MUST have `base_unit_id` set to the resolved unit ID
- **AND** the created product MUST keep `unit_id` set to the resolved unit ID for legacy compatibility
- **AND** the created product MUST populate `product_unit` from the resolved unit short name or name when available

#### Scenario: Purchase import-created product can be edited without changing unit
- **WHEN** a product created by Purchase import is opened on the Product edit page
- **AND** the product unit field is locked because the product is stock-managed or has stock
- **AND** the user changes only editable non-unit fields such as prices
- **THEN** the product update MUST pass unit validation using the product's canonical `base_unit_id`

### Requirement: Existing imported products with legacy unit data are repaired
The system SHALL provide an idempotent repair for existing products whose legacy unit field is populated but whose canonical base unit is missing.

#### Scenario: Existing product with unit_id receives base_unit_id
- **WHEN** an existing stock-managed product has `base_unit_id` empty
- **AND** the product has `unit_id` pointing to an existing unit
- **THEN** the repair MUST set `base_unit_id` to the product's `unit_id`
- **AND** the repair MUST leave the existing `unit_id` intact
- **AND** the repair MUST populate blank `product_unit` from the related unit short name or name

#### Scenario: Existing product without valid unit is not guessed
- **WHEN** an existing stock-managed product has `base_unit_id` empty
- **AND** the product does not have a valid `unit_id`
- **THEN** the repair MUST NOT assign a default unit
- **AND** the product MUST remain available for explicit manual correction or follow-up handling

#### Scenario: Repair is idempotent
- **WHEN** the product unit repair runs more than once
- **THEN** products already having `base_unit_id` MUST keep their existing canonical unit
- **AND** products repaired by a previous run MUST not receive duplicate or conflicting unit changes
