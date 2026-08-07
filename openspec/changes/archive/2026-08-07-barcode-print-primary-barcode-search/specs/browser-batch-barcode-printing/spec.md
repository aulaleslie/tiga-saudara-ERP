## ADDED Requirements

### Requirement: Print Barcode product discovery accepts primary barcodes
The Print Barcode workspace SHALL allow authorized operators to discover products by their stored primary `products.barcode` value in the existing product-search input, in addition to the existing name, SKU, category, and brand search fields. The workspace SHALL NOT resolve product unit-conversion barcodes as part of this capability.

#### Scenario: Typed primary barcode appears in search results
- **WHEN** an authorized operator enters all or part of a product's primary barcode in the Print Barcode product-search input
- **THEN** the matching product SHALL be available in the search results
- **AND** selecting it SHALL add it to the batch using the existing batch-row behavior

#### Scenario: Conversion barcode is not resolved
- **WHEN** an authorized operator enters a barcode that exists only on a product unit conversion and not on any product's primary barcode
- **THEN** the workspace SHALL NOT resolve that conversion barcode to the parent product through barcode lookup

### Requirement: Scanner Enter adds an exact primary-barcode match
The Print Barcode product-search input SHALL handle Enter as an exact primary-barcode selection action. For a trimmed input value that exactly matches one product's stored primary barcode, the workspace SHALL add that product through the existing product-selection flow and clear the search input. If no exact primary-barcode match exists, the workspace SHALL retain its existing search-results behavior and SHALL NOT add a product automatically.

#### Scenario: Scanner submits an exact primary barcode
- **WHEN** an authorized operator scans or types a product's complete primary barcode and submits the input with Enter
- **THEN** the workspace SHALL add that product to the batch without requiring the operator to click a search result
- **AND** it SHALL clear the search input

#### Scenario: Scanner selects a product already in the batch
- **WHEN** an authorized operator submits with Enter a complete primary barcode for a product already represented in the batch
- **THEN** the workspace SHALL retain one row for that product
- **AND** it SHALL increase that row's label quantity according to the existing product-selection behavior

#### Scenario: Submitted barcode has no exact primary match
- **WHEN** an authorized operator submits an input value with Enter that does not exactly match a stored primary product barcode
- **THEN** the workspace SHALL NOT add a product to the batch
- **AND** it SHALL preserve the existing matching-results or no-results feedback for the entered value
