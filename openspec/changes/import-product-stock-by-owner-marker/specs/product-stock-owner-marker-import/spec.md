## ADDED Requirements

### Requirement: CSV stock snapshot upload
The system SHALL provide a product stock quantity import flow that accepts CSV files with `Product Code`, `Product Name`, `Unassigned`, `Total Quantity`, and `Product Unit` columns.

#### Scenario: Accepted stock snapshot file
- **WHEN** an authorized user uploads a CSV containing the required stock snapshot columns
- **THEN** the system SHALL create an import batch, stage import rows, and process the rows asynchronously or through the established product import queue.

#### Scenario: Missing required stock snapshot columns
- **WHEN** an authorized user uploads a CSV missing any required stock snapshot column
- **THEN** the system SHALL reject or fail the batch with a header validation error that identifies the missing columns.

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

### Requirement: Import audit and row visibility
The system SHALL preserve batch-level and row-level visibility for stock snapshot import processing and SHALL record stock mutation audit data for successful overwrites.

#### Scenario: Successful row audit
- **WHEN** a row is imported successfully
- **THEN** the row SHALL show imported status, raw payload, resolved product reference, resolved owner/location context, previous quantity, and after quantity where supported by the import row schema.

#### Scenario: Stock transaction recorded
- **WHEN** the system overwrites stock for a row
- **THEN** the system SHALL create a stock transaction or equivalent audit record that captures the product, owner setting, target location, previous quantity, after quantity, user, and import reason.

#### Scenario: Failed row audit
- **WHEN** a row cannot be processed due to invalid data, missing owner setting, missing location, or product conflict
- **THEN** the row SHALL show error status and an actionable error message without blocking unrelated valid rows in the same batch.
