## MODIFIED Requirements

### Requirement: CSV stock snapshot upload
The system SHALL replace the standalone CSV stock snapshot upload path with the Accurate XLSX price-and-stock snapshot workflow. New stock snapshots SHALL be accepted through the XLSX workflow using `Name*`, `SellPrice`, and `Stock` columns; historical CSV snapshot batches SHALL remain visible for audit.

#### Scenario: Accurate XLSX stock snapshot is accepted
- **WHEN** an authorized user uploads a readable Accurate XLSX workbook containing `Name*`, `SellPrice`, and `Stock`
- **THEN** the system SHALL create and queue a `sales_price_snapshot` batch that applies the workbook's stock values

#### Scenario: User seeks a new stock snapshot upload
- **WHEN** an authorized user opens the product import area to upload a new stock snapshot
- **THEN** the system SHALL direct the user to the Accurate XLSX price-and-stock snapshot workflow
- **AND** the system SHALL NOT present the retired CSV stock snapshot template as the supported path

### Requirement: Owner marker normalization
The system SHALL derive the target owner setting from shared DAIZU-aware product-name normalization and remove ordinary markers before matching existing products.

#### Scenario: DAIZU ownership takes precedence
- **WHEN** a row product name meets the established DAIZU criteria
- **THEN** the system SHALL route the row to DAIZU KEDELAI regardless of a leading `*`, trailing `TP`, or no ordinary marker

#### Scenario: Leading asterisk marker
- **WHEN** a row product name starts with `*` and does not meet the DAIZU criteria
- **THEN** the system SHALL route the row to CV TIGA NUSA COMPUTER and use the product name with the leading `*` and surrounding marker whitespace removed

#### Scenario: Trailing TP marker
- **WHEN** a row product name ends with `TP` as a trailing marker and does not meet the DAIZU criteria
- **THEN** the system SHALL route the row to CV TOP IT INTERNUSA and use the product name with the trailing `TP` marker removed

#### Scenario: No owner marker
- **WHEN** a row product name has no leading `*` marker and no trailing `TP` marker and does not meet the DAIZU criteria
- **THEN** the system SHALL route the row to PERDANA and use the product name through standard shared normalization

### Requirement: Product matching and creation
The system SHALL match only existing products by shared normalized clean product identity and SHALL never create products from an Accurate XLSX stock snapshot row.

#### Scenario: Product already exists
- **WHEN** a row's normalized clean product name uniquely matches an existing product according to the shared import matching rules
- **THEN** the system SHALL use that existing product
- **AND** the system SHALL NOT create a duplicate product for a different owner marker

#### Scenario: Product does not exist
- **WHEN** a row's normalized clean product name does not uniquely match an existing product
- **THEN** the system SHALL skip or error the row with an actionable unmatched or ambiguous identity reason
- **AND** the system SHALL NOT create a product, unit, price row, stock row, or transaction

### Requirement: Stock overwrite from total quantity
The system SHALL overwrite the target product/location stock quantity using the Accurate XLSX row `Stock` value, including zero and negative quantities.

#### Scenario: Signed stock overwrite
- **WHEN** a matched row has a positive, zero, or negative `Stock` value
- **THEN** the system SHALL set the target product/location stock quantity to that exact signed value

#### Scenario: PKP owner bucket overwrite
- **WHEN** a matched row resolves to a PKP owner setting
- **THEN** the system SHALL set the target stock total and tax quantity bucket to `Stock`
- **AND** the system SHALL set the non-tax quantity bucket to `0`
- **AND** the system SHALL record transaction bucket deltas consistently

#### Scenario: Non-PKP owner bucket overwrite
- **WHEN** a matched row resolves to a non-PKP owner setting
- **THEN** the system SHALL set the target stock total and non-tax quantity bucket to `Stock`
- **AND** the system SHALL set the tax quantity bucket to `0`
- **AND** the system SHALL record transaction bucket deltas consistently

