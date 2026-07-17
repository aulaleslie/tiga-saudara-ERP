## MODIFIED Requirements

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

