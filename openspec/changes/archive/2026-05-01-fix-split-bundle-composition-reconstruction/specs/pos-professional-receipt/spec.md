## MODIFIED Requirements

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
