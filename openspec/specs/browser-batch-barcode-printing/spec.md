## Purpose

Provide authorized users with browser-based batch barcode printing for 55 mm × 40 mm labels, using selected-business non-tier sale prices and one print action per batch.
## Requirements
### Requirement: Authorized users can prepare a business-priced barcode batch
The system SHALL provide the existing Print Barcode workspace to users authorized by `barcodes.print`. The workspace SHALL support selecting multiple products, maintaining one row per product, entering and removing per-product label quantities, showing the aggregate label total, and previewing the complete selected-business batch. SKU on the workspace and label SHALL mean `products.product_code`.

#### Scenario: Operator adds several products to a batch
- **WHEN** an authorized user selects Product A and Product B and requests quantities 3 and 2
- **THEN** the workspace SHALL show one row for each product
- **AND** it SHALL show a total of 5 labels
- **AND** the batch preview SHALL contain five labels in the requested product/quantity order

#### Scenario: Operator selects a product already in the batch
- **WHEN** an authorized user selects a product that is already represented by a batch row
- **THEN** the system SHALL retain a single row for that product
- **AND** it SHALL merge the additional selection into that row's quantity rather than create a duplicate row

#### Scenario: Unauthorized user requests the barcode workspace
- **WHEN** a user without `barcodes.print` requests the Print Barcode route or batch print endpoint
- **THEN** the system SHALL deny access

### Requirement: Batch labels use the authorized selected-business non-tier sale price
The workspace SHALL default its selected business to `session('setting_id')`. A user may select another business only when authorized under the established document-business override rules. The system SHALL resolve each label price exclusively from `product_prices.sale_price` for the selected business and SHALL display it using the application currency formatter.

#### Scenario: Active business price is displayed
- **WHEN** the active session setting is Business A and a selected product has a Business A product-price row with `sale_price` 12500
- **THEN** the batch preview and print document SHALL display the formatted value of 12500 for that product
- **AND** they SHALL NOT use its tier price or a price belonging to another business

#### Scenario: Authorized operator changes business
- **WHEN** a Super Admin or user with business-override permission selects Business B
- **THEN** the system SHALL authorize Business B before exposing its price data
- **AND** it SHALL recalculate the displayed batch prices using Business B product-price rows

#### Scenario: Product has no valid selected-business non-tier price
- **WHEN** a selected product has no `product_prices` row for the selected business or its `sale_price` is null
- **THEN** preview and print submission SHALL be rejected
- **AND** the error SHALL identify the affected product and SKU
- **AND** the system SHALL NOT substitute a tier, global, or zero price

### Requirement: Print submission validates and expands a bounded product batch
The system SHALL expose a protected dedicated batch-print endpoint accepting a selected setting ID and `items` containing product IDs and quantities. The endpoint SHALL re-authorize the business, validate the complete request, load unique selected products and selected-business price rows in a bounded lookup, resolve each nonblank barcode to EAN-13 or general Code 128 according to its barcode semantics and stored symbology, and expand each valid quantity into individual label records before rendering.

#### Scenario: Valid batch expands into individual labels
- **WHEN** the endpoint receives Product A with quantity 3 and Product B with quantity 2
- **THEN** it SHALL render one HTML document containing five label records
- **AND** the records SHALL contain three copies of Product A followed by two copies of Product B

#### Scenario: Invalid item quantity is rejected
- **WHEN** an item quantity is zero, negative, fractional, non-numeric, or greater than 100
- **THEN** the endpoint SHALL reject the batch with a validation error
- **AND** it SHALL not render a print document

#### Scenario: Batch exceeds total safeguard
- **WHEN** the sum of requested quantities exceeds 200 labels
- **THEN** the endpoint SHALL reject the batch with a total-batch validation error
- **AND** it SHALL not render a partial document

#### Scenario: Requested product is absent or invalid for printing
- **WHEN** an item product ID does not resolve to a product, its barcode is blank, or its explicitly EAN-13 barcode cannot be rendered as EAN-13
- **THEN** the endpoint SHALL reject the complete batch
- **AND** it SHALL identify each affected product ID or resolved product/SKU as applicable

### Requirement: Each label contains consistent barcode and product information
For every expanded label record, the system SHALL render the product name, SKU (`product_code`), a server-generated SVG barcode, the barcode value as text, and the formatted selected-business non-tier sale price. Product-provided text SHALL be escaped. The stored symbology SHALL be normalized to recognize `EAN13` and `EAN-13`, as well as recognized renderer types `C128`, `C39`, `UPCA`, `EAN8`, and their supported aliases. When stored symbology is recognized, the system SHALL render the barcode using its normalized renderer type. An explicitly EAN-13 product SHALL render as EAN-13 only when its barcode is valid EAN-13. When stored symbology is absent or unrecognized, the system SHALL render a valid 13-digit EAN-13 barcode as EAN-13 and SHALL otherwise render the nonblank barcode with the installed renderer's general Code 128 type, `C128`. A SKU of at most 40 characters SHALL be displayed in full; a longer SKU SHALL display its first 39 characters followed by a visible `…`, without altering the stored product code or encoded barcode value.

#### Scenario: EAN-13 product is rendered with its stored symbology
- **WHEN** a selected product has a valid barcode and `product_barcode_symbology` of `EAN13`
- **THEN** the rendered SVG SHALL represent that barcode using EAN-13
- **AND** the barcode value printed as text SHALL match the stored value including leading zeroes

#### Scenario: Unspecified symbology infers valid EAN-13
- **WHEN** a selected product has no stored barcode symbology and its barcode is a 13-digit value with a correct EAN-13 check digit
- **THEN** the rendered SVG SHALL represent that barcode using EAN-13
- **AND** the barcode value printed as text SHALL match the stored value including leading zeroes

#### Scenario: Recognized stored symbology is respected
- **WHEN** a selected product has `product_barcode_symbology` set to a recognized renderer type (`C128`, `CODE128`, `C39`, `CODE39`, `UPCA`, `UPC-A`, `EAN8`, `EAN-8`)
- **THEN** the rendered SVG SHALL represent that barcode using the normalized renderer type
- **AND** the label SHALL use the stored symbology regardless of the barcode content

#### Scenario: Unspecified or unrecognized symbology falls back to Code 128
- **WHEN** a selected product has a nonblank barcode and its stored symbology is absent or unrecognized but its barcode is not valid EAN-13
- **THEN** the rendered SVG SHALL represent the unchanged barcode value using `C128`
- **AND** the label SHALL not be rejected solely because its stored symbology is absent or unrecognized

#### Scenario: Explicit invalid EAN-13 is rejected
- **WHEN** a selected product has `product_barcode_symbology` of `EAN13` or `EAN-13` and its barcode is not valid EAN-13
- **THEN** preview and print submission SHALL reject the product with an actionable rendering error
- **AND** the system SHALL NOT silently render it as Code 128

#### Scenario: Label carries all required display data
- **WHEN** a valid selected product is expanded into a label
- **THEN** the label SHALL include its product name, product code, barcode SVG, barcode value, and formatted selected-business sale price

#### Scenario: SKU within the practical label limit prints in full
- **WHEN** a product code contains 40 or fewer characters
- **THEN** the label SHALL show the complete code without an ellipsis

#### Scenario: SKU over the practical label limit is explicitly truncated
- **WHEN** a product code contains more than 40 characters
- **THEN** the label SHALL show exactly its first 39 characters followed by `…`
- **AND** the stored product code and barcode value SHALL remain complete and unchanged

### Requirement: The batch prints through one browser print action on 55 mm × 40 mm pages
The rendered print document SHALL use one 55 mm × 40 mm page per label, with matching `@page` and label-element dimensions, no CSS page margin, a minimum 2 mm safe content margin, and page breaks between labels only. It SHALL invoke `window.print()` once after document load and provide a visible manual Print fallback control.

#### Scenario: Multi-label batch opens one print dialog
- **WHEN** the browser opens a valid five-label print document
- **THEN** the document SHALL invoke `window.print()` exactly once
- **AND** the operator SHALL receive one browser print dialog for the whole batch
- **AND** each label SHALL be a separate browser print page

#### Scenario: Operator dismisses automatic printing
- **WHEN** the operator dismisses or the browser suppresses the automatic print dialog
- **THEN** the print document SHALL retain a visible Print control
- **AND** activating it SHALL initiate printing for the entire batch

#### Scenario: Print layout preserves a safe label area
- **WHEN** a label is rendered for 55 mm × 40 mm media
- **THEN** product text, barcode, barcode value, and price SHALL remain within the label safe area
- **AND** content SHALL NOT overflow onto an adjacent browser print page

### Requirement: Browser printing documents operational driver limitations
The Print Barcode workspace and print document SHALL communicate the required cashier setup for the Blueprint ECO80BT Windows driver: USB connection, 55 mm × 40 mm paper size, gap-label media, actual size/100% scaling, no margins, one page per sheet, one copy, headers/footers off, duplex off, and matching orientation. The feature SHALL document that the application cannot select the printer, enforce driver settings, detect gap/media faults, or verify physical print success.

#### Scenario: Cashier prepares a batch for printing
- **WHEN** an operator opens the barcode batch workspace or print document
- **THEN** the system SHALL show concise printer setup instructions
- **AND** it SHALL state that browser copies must remain 1 because label copies are expanded by the application

#### Scenario: Physical acceptance testing is performed
- **WHEN** the feature is evaluated for operational acceptance
- **THEN** testing SHALL include a three-label test and a 100-label sequential test on physical 55 mm × 40 mm gap media
- **AND** acceptance SHALL require one HTML page per physical label without blank labels, skipped labels, duplicated labels, cumulative drift, clipping, or unscannable barcodes

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

