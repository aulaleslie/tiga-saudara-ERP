## MODIFIED Requirements

### Requirement: Receipt shows bundle composition without component prices
The POS receipt SHALL show complete bundle component information beneath bundled parent item lines when bundle composition context is available, while keeping receipt totals based only on the parent customer-facing line totals.

#### Scenario: Completed split bundle receipt shows full component set
- **WHEN** a completed POS receipt or reprint receipt is rendered for a checkout that split bundle revenue across multiple Sales documents
- **THEN** the receipt MUST show every component that belongs to the bundled parent item line
- **AND** each component row MUST include the component name and remaining customer quantity for ordinary completed receipts, or original quantity for historical receipts
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
- **THEN** the receipt grand total, payment rows, and change rows MUST remain consistent with the current purchase projection for ordinary completed receipts, or equal to original checkout totals for explicit historical receipts
- **AND** component rows MUST NOT be summed as separate billable lines

#### Scenario: Receipt with no bundle context remains unchanged
- **WHEN** a receipt line has no bundle composition context
- **THEN** the receipt MUST render the line using the existing item, quantity, discount, unit breakdown, and total behavior

#### Scenario: Returned bundle presentation
- **WHEN** a completed cash return reduces a bundle parent quantity
- **THEN** the main bundle and nested component quantities reflect the remaining purchase and the returned bundle appears in return details
- **AND** a fully returned bundle appears only in return details

#### Scenario: Return section fits thermal width
- **WHEN** return references, serial pairs, and large monetary values are printed
- **THEN** descriptions and serials wrap within the thermal width and monetary values remain intact and right aligned
