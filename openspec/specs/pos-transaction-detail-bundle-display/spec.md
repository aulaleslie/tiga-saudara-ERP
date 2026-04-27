# pos-transaction-detail-bundle-display Specification

## Purpose
Ensures that POS transaction detail pages accurately show the composition of product bundles, including both draft state and completed checkout state.

## Requirements

### Requirement: Transaction detail shows bundle composition
The POS transaction detail page SHALL show bundle component information beneath bundled parent transaction rows when bundle composition context is available.

#### Scenario: Completed split bundle transaction shows component
- **WHEN** a completed POS transaction is viewed and its completed checkout has bundle component rows in any associated split Sales document
- **THEN** the transaction detail page MUST show each bundle component beneath the matching parent product row
- **AND** each component display MUST include the component name and customer quantity
- **AND** the component display MUST NOT include component price, subtotal, allocation amount, source owner, or split Sales document identifiers

#### Scenario: Draft transaction shows metadata bundle component
- **WHEN** a DRAFT or LOADED POS transaction is viewed and a transaction line has bundle component metadata
- **THEN** the transaction detail page MUST show the metadata bundle components beneath that parent line
- **AND** each component quantity MUST reflect the quantity the customer should receive for that transaction line

#### Scenario: Non-bundled transaction remains unchanged
- **WHEN** a POS transaction line has no bundle composition context
- **THEN** the transaction detail page MUST render the line without any bundle component section
