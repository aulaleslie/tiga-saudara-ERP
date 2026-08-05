## MODIFIED Requirements

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
