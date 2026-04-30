## MODIFIED Requirements

### Requirement: Transaction detail shows bundle composition
The POS transaction detail page SHALL show complete bundle component information beneath bundled parent transaction rows when bundle composition context is available.

#### Scenario: Completed split bundle transaction shows full component set
- **WHEN** a completed POS transaction is viewed and its completed checkout has bundle component rows distributed across one or more associated split Sales documents
- **THEN** the transaction detail page MUST show every component that belongs to the bundled parent transaction line
- **AND** each component display MUST include the component name and customer quantity
- **AND** each component display MUST NOT include component price, subtotal, allocation amount, source owner, or split Sales document identifiers

#### Scenario: Completed split bundle includes component-only ownership groups
- **WHEN** a bundled parent line is split across owners and one or more split Sales documents persist bundle components with parent sale detail quantity equal to zero
- **THEN** the transaction detail page MUST still include those component rows under the correct bundled parent transaction line
- **AND** the shown quantities MUST equal total customer-facing component quantities for that bundled line

#### Scenario: Mixed bundled and non-bundled parent rows do not leak components
- **WHEN** the same parent product appears in multiple rows and only some rows are bundled
- **THEN** bundle components MUST be shown only under bundled rows
- **AND** non-bundled rows MUST NOT inherit bundle components from bundled rows

#### Scenario: Draft transaction shows metadata bundle component
- **WHEN** a DRAFT or LOADED POS transaction is viewed and a transaction line has bundle component metadata
- **THEN** the transaction detail page MUST show the metadata bundle components beneath that parent line
- **AND** each component quantity MUST reflect the quantity the customer should receive for that transaction line

#### Scenario: Non-bundled transaction remains unchanged
- **WHEN** a POS transaction line has no bundle composition context
- **THEN** the transaction detail page MUST render the line without any bundle component section
