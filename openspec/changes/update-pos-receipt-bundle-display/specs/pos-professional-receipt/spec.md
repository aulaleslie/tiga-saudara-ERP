## ADDED Requirements

### Requirement: Receipt shows bundle composition without component prices
The POS receipt SHALL show bundle component information beneath bundled parent item lines when bundle composition context is available, while keeping receipt totals based only on the parent customer-facing line totals.

#### Scenario: Completed split bundle receipt shows component quantity
- **WHEN** a completed POS receipt or reprint receipt is rendered for a checkout that split bundle revenue across multiple Sales documents
- **THEN** the receipt MUST show bundle component rows beneath the matching bundled parent item
- **AND** each component row MUST include the component name and customer quantity
- **AND** each component row MUST NOT include component price, subtotal, allocation amount, source owner, or split Sales document identifiers

#### Scenario: Bundle component does not affect receipt totals
- **WHEN** a receipt renders bundle component rows beneath a bundled parent item
- **THEN** the receipt grand total, payment rows, and change rows MUST remain equal to the original POS checkout totals
- **AND** component rows MUST NOT be summed as separate billable lines

#### Scenario: Receipt with no bundle context remains unchanged
- **WHEN** a receipt line has no bundle composition context
- **THEN** the receipt MUST render the line using the existing item, quantity, discount, unit breakdown, and total behavior

### Requirement: Receipt footer tax notice is below tail dash
The POS receipt SHALL render `Harga sudah termasuk PPN CV TIGA COMPUTER © 2021` below the receipt tail dash line in a very small font.

#### Scenario: Tax notice placement
- **WHEN** a POS receipt or reprint receipt is rendered
- **THEN** the text `Harga sudah termasuk PPN CV TIGA COMPUTER © 2021` MUST appear below the dashed tail line
- **AND** the text MUST use a font smaller than the normal receipt line item font

#### Scenario: Tax notice is customer-facing footer text
- **WHEN** the receipt is printed
- **THEN** the tax notice MUST be included in the printed output
- **AND** it MUST NOT appear above the dashed tail line
