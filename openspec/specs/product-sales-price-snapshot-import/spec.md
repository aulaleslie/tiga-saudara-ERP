# product-sales-price-snapshot-import Specification

## Purpose
TBD - created by archiving change import-product-sales-price-snapshot. Update Purpose after archive.
## Requirements
### Requirement: Dedicated sales-price snapshot upload
The system SHALL provide a dedicated Product module workflow for uploading an Accurate-style XLSX product export as a sales-price snapshot, separate from generic product creation and stock snapshot imports. The workflow SHALL determine XLSX compatibility from the uploaded file's `.xlsx` name and readable Office Open XML workbook structure rather than requiring the server to infer an XLSX-specific MIME type.

#### Scenario: Authorized user opens the upload workflow
- **WHEN** a user authorized to edit products opens the product import area
- **THEN** the system SHALL provide a clearly labelled sales-price snapshot upload entry point
- **AND** the page SHALL explain that the import updates existing owner-specific product selling prices without creating products or changing stock

#### Scenario: Unauthorized user attempts access
- **WHEN** a user without product edit authorization attempts to open or submit the sales-price snapshot upload
- **THEN** the system SHALL deny access

#### Scenario: Valid workbook is submitted
- **WHEN** an authorized user submits a readable XLSX workbook named with an `.xlsx` extension and containing `Name*` and `SellPrice` columns
- **THEN** the system SHALL create a product import batch with type `sales_price_snapshot`
- **AND** the system SHALL queue the workbook for background processing
- **AND** the user SHALL be directed to the batch detail view

#### Scenario: Valid workbook has a generic server MIME type
- **WHEN** an authorized user submits a readable XLSX workbook named with an `.xlsx` extension
- **AND** server content detection reports `application/octet-stream` instead of an XLSX-specific MIME type
- **THEN** the system SHALL accept the workbook based on its readable XLSX structure
- **AND** the upload SHALL proceed through the normal sales-price snapshot batch workflow

#### Scenario: Unsupported file is submitted
- **WHEN** an authorized user submits a file that is not named with an `.xlsx` extension or is not structurally readable as an XLSX workbook
- **THEN** the system SHALL reject the upload before creating an import batch
- **AND** the system SHALL NOT queue processing or create price mutations

### Requirement: Workbook structure validation and row staging
The system SHALL validate the workbook structure and stage source rows before applying product prices.

#### Scenario: Required headers are present
- **WHEN** a workbook contains headers equivalent to `Name*` and `SellPrice` after BOM, case, and whitespace normalization
- **THEN** the system SHALL stage each data row with its worksheet row number and raw source values

#### Scenario: Required header is missing
- **WHEN** a workbook is missing `Name*` or `SellPrice`
- **THEN** the system SHALL fail the batch with an actionable missing-header message
- **AND** the system SHALL NOT update any product price

#### Scenario: Optional product code is absent
- **WHEN** the workbook omits `ProductCode` or a row has a blank product code
- **THEN** the system SHALL continue resolution through normalized product-name matching

#### Scenario: Workbook cannot be read
- **WHEN** the uploaded workbook is corrupt, encrypted, or otherwise unreadable
- **THEN** the system SHALL fail the batch with an actionable read error
- **AND** the system SHALL NOT update any product price

### Requirement: Shared owner-marker normalization
The system SHALL resolve the target owner setting and clean product name using the established import marker and Daizu rules before matching a product.

#### Scenario: Leading asterisk owner marker
- **WHEN** a source product name begins with `*`
- **AND** the product does not meet the existing Daizu criteria
- **THEN** the system SHALL resolve CV TIGA NUSA COMPUTER as the target setting
- **AND** the system SHALL remove the leading marker and its surrounding whitespace before product matching

#### Scenario: Trailing TP owner marker
- **WHEN** a source product name ends with the established trailing ` TP` marker
- **AND** the product does not meet the existing Daizu criteria
- **THEN** the system SHALL resolve CV TOP IT INTERNUSA as the target setting
- **AND** the system SHALL remove the trailing marker before product matching

#### Scenario: Product name has no owner marker
- **WHEN** a source product name has neither a leading `*` nor a trailing ` TP` marker
- **AND** the product does not meet the existing Daizu criteria
- **THEN** the system SHALL resolve PERDANA as the target setting
- **AND** the system SHALL retain the product name except for standard whitespace cleanup

#### Scenario: Daizu product takes precedence
- **WHEN** a source product name meets the existing Daizu product criteria
- **THEN** the system SHALL resolve DAIZU KEDELAI as the target setting regardless of the ordinary marker owner

#### Scenario: Resolved owner is not configured
- **WHEN** the owner rule resolves a company that has no matching setting
- **THEN** the system SHALL mark the row as an error with the unresolved owner name
- **AND** the system SHALL NOT update a product price for that row

### Requirement: Deterministic existing-product matching
The system SHALL match only existing products through deterministic code and shared normalized-name rules and SHALL NOT create products during sales-price snapshot processing.

#### Scenario: Unique product code match
- **WHEN** a row contains a nonblank product code that uniquely matches an existing product case-insensitively
- **THEN** the system SHALL select that product as the code-match candidate

#### Scenario: Unique clean-name exact match
- **WHEN** no product has been conclusively selected by product code
- **AND** the marker-free, whitespace-normalized name uniquely matches an existing product case-insensitively
- **THEN** the system SHALL select that existing product

#### Scenario: Unique canonical normalized-name match
- **WHEN** no exact clean-name match exists
- **AND** the name normalized through the shared sales-import normalization and alias rules uniquely matches an existing product
- **THEN** the system SHALL select that existing product

#### Scenario: Code and name identify different products
- **WHEN** a row's product code candidate and normalized-name candidate resolve to different existing products
- **THEN** the system SHALL mark the row as ambiguous
- **AND** the system SHALL NOT update either product

#### Scenario: Multiple products share a candidate identity
- **WHEN** an applicable exact or canonical identity matches more than one existing product
- **THEN** the system SHALL mark the row as ambiguous and report the candidate products
- **AND** the system SHALL NOT guess which product to update

#### Scenario: Product is not found
- **WHEN** neither product code nor normalized product name resolves an existing product
- **THEN** the system SHALL mark the row as skipped with an unmatched-product reason
- **AND** the system SHALL NOT create a product, unit, category, brand, stock row, or transaction

### Requirement: Positive selling-price validation
The system SHALL parse Accurate-style selling-price values and SHALL apply only finite prices greater than zero.

#### Scenario: Accurate formatted price is parsed
- **WHEN** `SellPrice` contains a value such as `400,000.00`
- **THEN** the system SHALL parse it as the decimal selling price `400000.00`

#### Scenario: Blank or zero selling price
- **WHEN** `SellPrice` is blank or resolves to zero
- **THEN** the system SHALL mark the row as skipped with a price-specific reason
- **AND** all existing price values SHALL remain unchanged

#### Scenario: Negative or non-numeric selling price
- **WHEN** `SellPrice` is negative, non-numeric, non-finite, or outside the supported database range
- **THEN** the system SHALL not apply the value
- **AND** the row SHALL show an actionable validation result

### Requirement: Owner-specific selling-tier synchronization
For each valid matched row, the system SHALL update the matched product's `product_prices` record for only the resolved owner setting and SHALL set all three selling tiers to the same imported `SellPrice`.

#### Scenario: Existing owner price row is updated
- **WHEN** a row resolves to a product, owner setting, and positive selling price
- **AND** the `(product_id, setting_id)` price row exists
- **THEN** the system SHALL set `sale_price`, `tier_1_price`, and `tier_2_price` to the imported selling price in one database transaction

#### Scenario: Owner price row is missing
- **WHEN** a row resolves to a product, owner setting, and positive selling price
- **AND** the `(product_id, setting_id)` price row does not exist
- **THEN** the system SHALL create that owner-specific price row
- **AND** `sale_price`, `tier_1_price`, and `tier_2_price` SHALL equal the imported selling price

#### Scenario: Other owner prices remain unchanged
- **WHEN** a row updates the resolved owner's price record
- **THEN** price records for every other setting SHALL remain unchanged

#### Scenario: Non-selling price data remains unchanged
- **WHEN** a row updates or creates the resolved owner's price record
- **THEN** the import SHALL NOT overwrite `last_purchase_price`, `average_purchase_price`, `purchase_tax_id`, or `sale_tax_id` on an existing row
- **AND** the import SHALL NOT update legacy product price fields, stock, bundle prices, or unit-conversion prices

#### Scenario: Reimported value already matches
- **WHEN** all three target selling tiers already equal the imported selling price
- **THEN** the row SHALL complete successfully as unchanged
- **AND** the system SHALL record that no price value changed

### Requirement: Duplicate target conflict protection
The system SHALL prevent workbook order from choosing a price when multiple source rows resolve to the same product and owner target inconsistently.

#### Scenario: Duplicate target has conflicting prices
- **WHEN** two or more source rows resolve to the same `(product_id, setting_id)` target with different positive selling prices
- **THEN** the system SHALL mark the conflicting target group as an error
- **AND** the system SHALL NOT apply any conflicting price from that group

#### Scenario: Duplicate target has the same price
- **WHEN** two or more source rows resolve to the same `(product_id, setting_id)` target with the same positive selling price
- **THEN** the system SHALL apply the target price at most once
- **AND** subsequent equivalent rows SHALL be reported as duplicates without causing an error or additional mutation

#### Scenario: Same product has different owner prices
- **WHEN** rows resolve to the same product but different owner settings
- **THEN** the rows SHALL be treated as independent targets
- **AND** each owner's three selling tiers SHALL receive its own imported value

### Requirement: Partial processing and transactional safety
The system SHALL isolate row-level price mutations so invalid or failed rows do not prevent unrelated valid targets from completing.

#### Scenario: One row fails while another is valid
- **WHEN** a staged row is unmatched, ambiguous, invalid, or fails during persistence
- **AND** another staged row is valid
- **THEN** the failed row SHALL NOT mutate price data
- **AND** the valid row SHALL still be eligible for successful processing

#### Scenario: Price persistence fails
- **WHEN** a database exception occurs while applying a valid target row
- **THEN** every mutation for that row SHALL roll back atomically
- **AND** the row SHALL be marked as an error without leaving partially updated tiers

#### Scenario: Batch completes with mixed outcomes
- **WHEN** all staged rows reach imported, skipped, duplicate, or error outcomes
- **THEN** the batch SHALL finalize with processed and outcome counts that account for every staged row

### Requirement: Sales-price snapshot audit visibility
The system SHALL identify sales-price snapshot batches distinctly and retain row-level evidence of matching and price effects.

#### Scenario: Successful changed row is displayed
- **WHEN** an authorized user views a successfully changed row
- **THEN** the system SHALL show the raw and clean product names, resolved product, match strategy, resolved owner, imported price, previous three selling prices, and resulting three selling prices

#### Scenario: Successful unchanged row is displayed
- **WHEN** an authorized user views a successfully processed row whose target prices already matched
- **THEN** the system SHALL identify the row as unchanged and show the matched product and owner

#### Scenario: Non-applied row is displayed
- **WHEN** a row is skipped, duplicated, ambiguous, or errors
- **THEN** the batch detail SHALL show an actionable outcome reason and available normalized identity, owner, candidate, or conflict context

#### Scenario: Batch type is displayed
- **WHEN** an authorized user views product import batch lists or details
- **THEN** a sales-price snapshot batch SHALL be labelled separately from product, stock snapshot, and sales HPP snapshot batches

#### Scenario: Incompatible undo is unavailable
- **WHEN** a sales-price snapshot batch completes
- **THEN** the system SHALL NOT offer the existing stock-oriented import undo action for that batch

