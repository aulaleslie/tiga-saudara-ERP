# pos-professional-receipt Specification

## Purpose
Specifies the professional layout and data requirements for thermal printer POS receipts, ensuring accurate display of item conversions and payment details.

## Requirements

### Requirement: Thermal Receipt Layout Redesign
The POS receipt view SHALL match the specified professional thermal layout, including a centered header with business contact details, a dashed line separator, and right-aligned totals.

#### Scenario: Business header alignment
- **WHEN** the receipt is rendered
- **THEN** the company name, address, email, and phone are centered at the top

#### Scenario: Date formatting
- **WHEN** the receipt is rendered
- **THEN** the date is formatted as "day Month, year HH:mm" (e.g., "01 Dec, 2025 22:38")

### Requirement: Unit Conversion Detail Breakdown
Each item line on the receipt SHALL include the base quantity and unit name, as well as any applicable unit conversion breakdown indented below the line.

#### Scenario: Item with unit breakdown
- **WHEN** an item is sold as a conversion (e.g., BOX containing 10 RIMs)
- **THEN** the receipt shows the primary line item and an indented breakdown: "{qty} {unit}(S) @ {unit_price}"

### Requirement: Correct Multi-Payment Nominal Display
The receipt SHALL display the correct non-zero nominal amount for every payment method used in the checkout process.

#### Scenario: Multi-payment nominal verification
- **WHEN** a checkout has multiple payments (e.g., CASH and QRIS)
### Requirement: Bolder Font Legibility for Thermal Printers
The receipt layout CSS SHALL be updated to use bolder font weight for global body text and headers to improve legibility on physical thermal printers.

#### Scenario: Bolder body text
- **WHEN** the thermal receipt is rendered
- **THEN** it MUST use a `font-weight` of at least 600 or equivalent bold style for most text elements
- **AND** headers SHOULD be even bolder if feasible (e.g., `font-weight: 700+`)
### Requirement: Receipt shows bundle composition without component prices
The POS receipt SHALL show complete bundle component information beneath bundled parent item lines when bundle composition context is available, while keeping receipt totals based only on the parent customer-facing line totals.

#### Scenario: Completed split bundle receipt shows full component set
- **WHEN** a completed POS receipt or reprint receipt is rendered for a checkout that split bundle revenue across multiple Sales documents
- **THEN** the receipt MUST show every component that belongs to the bundled parent item line
- **AND** each component row MUST include the component name and customer quantity
- **AND** each component row MUST NOT include component price, subtotal, allocation amount, source owner, or split Sales document identifiers

#### Scenario: Receipt includes component-only ownership groups
- **WHEN** a bundled parent line is split across owners and one or more split Sales documents persist bundle components with parent sale detail quantity equal to zero
- **THEN** the receipt MUST still include those component rows under the correct bundled parent line
- **AND** the shown quantities MUST equal total customer-facing component quantities for that bundled line

#### Scenario: Receipt with mixed bundled and non-bundled parent rows does not leak components
- **WHEN** the same parent product appears in multiple receipt rows and only some rows are bundled
- **THEN** bundle components MUST be shown only under bundled rows
- **AND** non-bundled rows MUST NOT inherit bundle components from bundled rows

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
