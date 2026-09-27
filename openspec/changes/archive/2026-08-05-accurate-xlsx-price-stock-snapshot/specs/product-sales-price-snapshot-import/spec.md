## MODIFIED Requirements

### Requirement: Dedicated sales-price snapshot upload
The system SHALL provide a dedicated Product module workflow for uploading an Accurate-style XLSX product export as an owner-specific selling-price and stock snapshot, separate from generic product creation and the retired CSV stock snapshot upload. The workflow SHALL determine XLSX compatibility from the uploaded file's `.xlsx` name and readable Office Open XML workbook structure rather than requiring the server to infer an XLSX-specific MIME type.

#### Scenario: Authorized user opens the upload workflow
- **WHEN** a user authorized to edit products opens the product import area
- **THEN** the system SHALL provide a clearly labelled Accurate price-and-stock snapshot upload entry point
- **AND** the page SHALL explain that the import updates existing owner-specific selling prices and replaces owner-location stock without creating products

#### Scenario: Valid workbook is submitted
- **WHEN** an authorized user submits a readable XLSX workbook named with an `.xlsx` extension and containing `Name*`, `SellPrice`, and `Stock` columns
- **THEN** the system SHALL create a product import batch with type `sales_price_snapshot`
- **AND** the system SHALL queue the workbook for background processing
- **AND** the user SHALL be directed to the batch detail view

#### Scenario: Valid workbook has a generic server MIME type
- **WHEN** an authorized user submits a readable XLSX workbook named with an `.xlsx` extension
- **AND** server content detection reports `application/octet-stream` instead of an XLSX-specific MIME type
- **THEN** the system SHALL accept the workbook based on its readable XLSX structure
- **AND** the upload SHALL proceed through the normal price-and-stock snapshot batch workflow

#### Scenario: Unauthorized user attempts access
- **WHEN** a user without product edit authorization attempts to open or submit the price-and-stock snapshot upload
- **THEN** the system SHALL deny access

#### Scenario: Unsupported file is submitted
- **WHEN** an authorized user submits a file that is not named with an `.xlsx` extension or is not structurally readable as an XLSX workbook
- **THEN** the system SHALL reject the upload before creating an import batch
- **AND** the system SHALL NOT queue processing or create price or stock mutations

### Requirement: Workbook structure validation and row staging
The system SHALL validate the workbook structure and stage source rows before applying product prices or stock snapshots.

#### Scenario: Required headers are present
- **WHEN** a workbook contains headers equivalent to `Name*`, `SellPrice`, and `Stock` after BOM, case, and whitespace normalization
- **THEN** the system SHALL stage each data row with its worksheet row number and raw source values, including optional `ProductCode` and `*Unit` when present

#### Scenario: Required header is missing
- **WHEN** a workbook is missing `Name*`, `SellPrice`, or `Stock`
- **THEN** the system SHALL fail the batch with an actionable missing-header message
- **AND** the system SHALL NOT update any product price or stock

#### Scenario: Optional product code is absent
- **WHEN** a workbook omits `ProductCode` or a row has a blank product code
- **THEN** the system SHALL continue resolution through normalized product-name matching

#### Scenario: Workbook cannot be read
- **WHEN** the uploaded workbook is corrupt, encrypted, or otherwise unreadable
- **THEN** the system SHALL fail the batch with an actionable read error
- **AND** the system SHALL NOT update any product price or stock

### Requirement: Owner-specific selling-tier synchronization
For each valid matched row, the system SHALL update the matched product's `product_prices` record for only the resolved owner setting and SHALL set all three selling tiers to the same imported `SellPrice` as part of the atomic price-and-stock snapshot mutation.

#### Scenario: Existing owner price row is updated
- **WHEN** a row resolves to a product, owner setting, and positive selling price
- **AND** the `(product_id, setting_id)` price row exists
- **THEN** the system SHALL set `sale_price`, `tier_1_price`, and `tier_2_price` to the imported selling price in the transaction that applies the row's stock snapshot

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
- **AND** the import SHALL NOT update legacy product price fields, bundle prices, or unit-conversion prices

#### Scenario: Reimported value already matches
- **WHEN** all three target selling tiers already equal the imported selling price
- **THEN** the row SHALL remain eligible to apply its imported stock snapshot
- **AND** the system SHALL record that no price value changed

### Requirement: Duplicate target conflict protection
The system SHALL prevent workbook order from choosing price or stock when multiple source rows resolve to the same product and owner target inconsistently.

#### Scenario: Duplicate target has conflicting price or stock values
- **WHEN** two or more source rows resolve to the same `(product_id, setting_id)` target with different positive selling prices or different signed stock values
- **THEN** the system SHALL mark the conflicting target group as an error
- **AND** the system SHALL NOT apply any price or stock mutation from that group

#### Scenario: Duplicate target has equivalent values
- **WHEN** two or more source rows resolve to the same `(product_id, setting_id)` target with the same positive selling price and signed stock value
- **THEN** the system SHALL apply the target price and stock at most once
- **AND** subsequent equivalent rows SHALL be reported as duplicates without causing an error or additional mutation

#### Scenario: Same product has different owner snapshots
- **WHEN** rows resolve to the same product but different owner settings
- **THEN** the rows SHALL be treated as independent targets
- **AND** each owner's selling tiers and owner-location stock SHALL receive its own imported values

### Requirement: Atomic owner price and stock snapshot synchronization
For each valid matched owner/product target, the system SHALL synchronize the three selling tiers and owner-location stock snapshot in one database transaction.

#### Scenario: Valid matched row updates both effects
- **WHEN** a row resolves to an existing product, owner setting, owner location, positive selling price, and signed stock value
- **THEN** the system SHALL set that owner's `sale_price`, `tier_1_price`, and `tier_2_price` to `SellPrice`
- **AND** the system SHALL replace the resolved product/location stock with `Stock`
- **AND** the system SHALL commit both effects atomically

#### Scenario: Stock persistence fails
- **WHEN** an exception occurs while applying a valid row's stock snapshot or adjustment transaction
- **THEN** every price and stock mutation for that target SHALL roll back
- **AND** the row SHALL be marked as an error without partial selling-tier or inventory changes

#### Scenario: Unmatched product is skipped
- **WHEN** neither product code nor normalized product name resolves an existing product
- **THEN** the system SHALL mark the row as skipped with an unmatched-product reason
- **AND** the system SHALL NOT create a product, unit, price row, stock row, or transaction

### Requirement: Sales-price snapshot audit visibility
The system SHALL identify price-and-stock snapshot batches distinctly and retain row-level evidence of matching, price effects, and inventory effects.

#### Scenario: Successful row is displayed
- **WHEN** an authorized user views a successfully changed row
- **THEN** the system SHALL show the raw and clean product names, resolved product, match strategy, resolved owner and location, imported price, previous and resulting selling tiers, imported stock, previous and resulting stock, tax/non-tax bucket effects, and stock transaction reference

#### Scenario: Incompatible undo is unavailable
- **WHEN** a price-and-stock snapshot batch completes
- **THEN** the system SHALL NOT offer the existing stock-oriented import undo action for that batch

## ADDED Requirements

### Requirement: Shared DAIZU-aware inventory ownership
The system SHALL use the established shared owner-marker and DAIZU rules to determine the owner setting, target location, and inventory tax bucket for each Accurate XLSX row.

#### Scenario: DAIZU product receives DAIZU stock
- **WHEN** a source product name meets the existing DAIZU product criteria
- **THEN** the system SHALL resolve DAIZU KEDELAI regardless of an ordinary marker
- **AND** the system SHALL replace stock at DAIZU's resolved location
- **AND** the system SHALL use DAIZU's PKP status to populate the tax or non-tax stock bucket
