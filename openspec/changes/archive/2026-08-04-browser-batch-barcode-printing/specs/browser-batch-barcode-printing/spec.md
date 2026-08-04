## ADDED Requirements

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
The system SHALL expose a protected dedicated batch-print endpoint accepting a selected setting ID and `items` containing product IDs and quantities. The endpoint SHALL re-authorize the business, validate the complete request, load unique selected products and selected-business price rows in a bounded lookup, and expand each valid quantity into individual label records before rendering.

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
- **WHEN** an item product ID does not resolve to a product, its barcode is blank, or its barcode symbology is unsupported
- **THEN** the endpoint SHALL reject the complete batch
- **AND** it SHALL identify each affected product ID or resolved product/SKU as applicable

### Requirement: Each label contains consistent barcode and product information
For every expanded label record, the system SHALL render the product name, SKU (`product_code`), a server-generated SVG barcode, the barcode value as text, and the formatted selected-business non-tier sale price. SVG SHALL be generated from the stored barcode and its supported stored symbology; product-provided text SHALL be escaped.

The printed SKU SHALL follow a deterministic display rule: a `product_code` of 40 characters or fewer SHALL be rendered in full; a longer value SHALL be rendered as its first 39 characters followed by the Unicode ellipsis `…`. The SKU SHALL be rendered in the standard readable label font and SHALL NOT be reduced below that size to accommodate long values. Truncation SHALL be applied server-side and SHALL NOT rely on CSS ellipsis, hidden overflow, or any unmarked clipping mechanism. This rule SHALL apply only to the physical print layout: `products.product_code` SHALL NOT be mutated, the batch-selection workspace MAY display the full value, and the barcode value SHALL remain complete and machine-readable.

#### Scenario: SKU within the display limit is printed in full
- **WHEN** a selected product has a `product_code` of 40 characters or fewer
- **THEN** the label SHALL print the complete `product_code`
- **AND** it SHALL NOT append an ellipsis

#### Scenario: SKU beyond the display limit is explicitly truncated
- **WHEN** a selected product has a `product_code` longer than 40 characters
- **THEN** the label SHALL print its first 39 characters followed by `…`
- **AND** the remaining characters SHALL NOT appear in the label body
- **AND** the stored `product_code` and the printed barcode value SHALL remain unchanged

#### Scenario: EAN-13 product is rendered with its stored symbology
- **WHEN** a selected product has a valid barcode and `product_barcode_symbology` of `EAN13`
- **THEN** the rendered SVG SHALL represent that barcode using EAN-13
- **AND** the barcode value printed as text SHALL match the stored value including leading zeroes

#### Scenario: Label carries all required display data
- **WHEN** a valid selected product is expanded into a label
- **THEN** the label SHALL include its product name, product code, barcode SVG, barcode value, and formatted selected-business sale price

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
