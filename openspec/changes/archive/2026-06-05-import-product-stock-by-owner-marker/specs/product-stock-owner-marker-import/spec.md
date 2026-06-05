## ADDED Requirements

### Requirement: CSV stock snapshot upload
The system SHALL provide a product stock quantity import flow that accepts CSV files with `Product Code`, `Product Name`, `Unassigned`, `Total Quantity`, and `Product Unit` columns.

#### Scenario: Accepted stock snapshot file
- **WHEN** an authorized user uploads a CSV containing the required stock snapshot columns
- **THEN** the system SHALL create an import batch, stage import rows, and process the rows asynchronously or through the established product import queue.

#### Scenario: Missing required stock snapshot columns
- **WHEN** an authorized user uploads a CSV missing any required stock snapshot column
- **THEN** the system SHALL reject or fail the batch with a header validation error that identifies the missing columns.

### Requirement: Stock snapshot upload user interface
The system SHALL expose stock snapshot import as an explicit product import mode or dedicated product stock import page instead of requiring users to discover it through generic product upload header detection.

#### Scenario: Stock snapshot upload entry point
- **WHEN** an authorized user opens the product import upload interface
- **THEN** the system SHALL provide a visible stock snapshot import option or page labelled for warehouse stock quantity snapshot import.

#### Scenario: Stock snapshot template download
- **WHEN** an authorized user requests the stock snapshot template
- **THEN** the system SHALL download a CSV template with `Product Code`, `Product Name`, `Unassigned`, `Total Quantity`, and `Product Unit` columns.

#### Scenario: Owner marker rule explanation
- **WHEN** an authorized user views the stock snapshot upload interface
- **THEN** the system SHALL explain that leading `*` routes to CV TIGA NUSA COMPUTER, trailing `TP` routes to CV TOP IT INTERNUSA, and no marker routes to PERDANA.

#### Scenario: Import type visibility
- **WHEN** an authorized user views product import batches or a product import batch detail
- **THEN** the system SHALL show whether each batch is a product import or stock snapshot import.

### Requirement: Owner marker normalization
The system SHALL derive the target owner setting from the product name marker and remove the marker before matching or creating products.

#### Scenario: Leading asterisk marker
- **WHEN** a row product name starts with `*`
- **THEN** the system SHALL route the row to CV TIGA NUSA COMPUTER and use the product name with the leading `*` and surrounding marker whitespace removed.

#### Scenario: Trailing TP marker
- **WHEN** a row product name ends with `TP` as a trailing marker
- **THEN** the system SHALL route the row to CV TOP IT INTERNUSA and use the product name with the trailing `TP` marker removed.

#### Scenario: No owner marker
- **WHEN** a row product name has no leading `*` marker and no trailing `TP` marker
- **THEN** the system SHALL route the row to PERDANA and use the product name unchanged except for standard whitespace normalization.

### Requirement: Owner location resolution
The system SHALL update stock at the first configured location for the owner setting resolved from the marker.

#### Scenario: Owner has a location
- **WHEN** a row resolves to an owner setting with at least one location
- **THEN** the system SHALL use the first location for that setting as the target `product_stocks.location_id`.

#### Scenario: Owner setting or location is missing
- **WHEN** a row resolves to an owner setting that cannot be found or has no configured location
- **THEN** the system SHALL mark the row as an error without updating product stock for that row.

### Requirement: Product matching and creation
The system SHALL match products by normalized clean product identity and create missing products from the stock snapshot row.

#### Scenario: Product already exists
- **WHEN** a row clean product name or product code matches an existing product according to the import matching rules
- **THEN** the system SHALL use the existing product and SHALL NOT create a duplicate product for a different owner marker.

#### Scenario: Product does not exist
- **WHEN** a row clean product name and product code do not match an existing product
- **THEN** the system SHALL create a stock-managed product using the clean product name, optional product code, and product unit.

#### Scenario: Same clean product under multiple owners
- **WHEN** multiple rows have the same clean product name under different owner markers
- **THEN** the system SHALL keep one global product identity and update separate owner-location stock rows.

### Requirement: Stock overwrite from total quantity
The system SHALL overwrite the target product/location stock quantity using the row `Total Quantity` value, including zero and negative quantities.

#### Scenario: Positive quantity overwrite
- **WHEN** a row has a positive `Total Quantity`
- **THEN** the system SHALL set the target product/location stock quantity to that exact value.

#### Scenario: Zero quantity overwrite
- **WHEN** a row has `Total Quantity` equal to `0`
- **THEN** the system SHALL create or update the target product/location stock row with quantity `0`.

#### Scenario: Negative quantity overwrite
- **WHEN** a row has a negative `Total Quantity`
- **THEN** the system SHALL create or update the target product/location stock row with that negative quantity.

#### Scenario: Unassigned value ignored for quantity
- **WHEN** a row includes both `Unassigned` and `Total Quantity`
- **THEN** the system SHALL use `Total Quantity` as the stock quantity and SHALL NOT use `Unassigned` to calculate stock.

#### Scenario: PKP owner bucket overwrite
- **WHEN** a row resolves to a PKP owner setting
- **THEN** the system SHALL set the target stock total to `Total Quantity`, set the tax quantity bucket to `Total Quantity`, set the non-tax quantity bucket to `0`, and record transaction bucket deltas consistently.

#### Scenario: Non-PKP owner bucket overwrite
- **WHEN** a row resolves to a non-PKP owner setting
- **THEN** the system SHALL set the target stock total to `Total Quantity`, set the non-tax quantity bucket to `Total Quantity`, set the tax quantity bucket to `0`, and record transaction bucket deltas consistently.

#### Scenario: Product quantity projection consistency
- **WHEN** the import overwrites one or more owner-location stock rows for a product
- **THEN** the product aggregate quantity SHALL remain consistent with the sum of that product's location stock quantities after each successful row.

### Requirement: Import audit and row visibility
The system SHALL preserve batch-level and row-level visibility for stock snapshot import processing and SHALL record stock mutation audit data for successful overwrites.

#### Scenario: Successful row audit
- **WHEN** a row is imported successfully
- **THEN** the row SHALL show imported status, raw payload, resolved product reference, resolved owner/location context, previous quantity, and after quantity where supported by the import row schema.

#### Scenario: Row-level stock effect visibility
- **WHEN** an authorized user views a successful stock snapshot import row
- **THEN** the system SHALL show the clean product name, resolved owner, target location, imported total quantity, previous quantity, after quantity, tax/non-tax bucket effect, and stock transaction reference where supported by the schema.

#### Scenario: Stock transaction recorded
- **WHEN** the system overwrites stock for a row
- **THEN** the system SHALL create a stock transaction or equivalent audit record that captures the product, owner setting, target location, previous quantity, after quantity, user, and import reason.

#### Scenario: Failed row audit
- **WHEN** a row cannot be processed due to invalid data, missing owner setting, missing location, or product conflict
- **THEN** the row SHALL show error status and an actionable error message without blocking unrelated valid rows in the same batch.

#### Scenario: Missing owner setting visibility
- **WHEN** a row marker resolves to an owner name that is not configured
- **THEN** the row SHALL fail without product stock mutation and SHALL show which owner mapping could not be resolved.

#### Scenario: Missing owner location visibility
- **WHEN** a row resolves to an owner setting without a configured location
- **THEN** the row SHALL fail without product stock mutation and SHALL show that the owner has no target location configured.
