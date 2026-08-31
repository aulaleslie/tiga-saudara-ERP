## Purpose

Extend Sales cart pricing to apply configurable row-total rounding to automatically priced bundle rows, ensuring consistent tax-inclusive totals while preserving manual pricing overrides and component informational integrity.

## ADDED Requirements

### Requirement: Sales bundled rows apply row-total rounding to automatically priced visible totals
When a user selects a product bundle in Sales create or Sales edit cart flows and the resulting automatically priced visible row is calculated through an eligible user interaction, Sales SHALL round its final tax-inclusive row total using the effective business configuration without modifying bundle component informational values or affecting manual unit-price or manual-line-total overrides.

#### Scenario: Automatic bundle rows receive row-total rounding
- **WHEN** a user adds a product to the Sales cart
- **AND** selects a product bundle whose `bundle_sale_price` is set
- **AND** the row calculation results in an automatically priced visible total
- **THEN** the final tax-inclusive visible row total SHALL use configured row-total rounding
- **AND** bundle component informational prices SHALL remain unchanged
- **AND** the rounding difference SHALL be absorbed by the parent row total

#### Scenario: Manual bundle row price override bypasses rounding
- **WHEN** a Sales cart row has a selected bundle
- **AND** the user manually changes the parent row price or row total
- **THEN** the row SHALL NOT receive automatic row-total rounding
- **AND** the user-edited price SHALL be preserved exactly as entered

#### Scenario: Bundle component prices remain non-billable after rounding
- **WHEN** an automatically priced visible bundle row changes from `78999.00` to a rounded total of `79000.00`
- **THEN** each bundle component informational price SHALL retain its existing value
- **AND** the rounding difference SHALL NOT be distributed into component prices
- **AND** components SHALL remain non-billable context rows

