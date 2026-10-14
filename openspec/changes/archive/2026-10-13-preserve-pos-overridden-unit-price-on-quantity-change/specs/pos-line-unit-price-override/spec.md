# Spec Delta

## MODIFIED Requirements

### Requirement: Unit-price override SHALL recalculate the row through the canonical totals path
After the authoritative unit price is set, the system SHALL recalculate row gross, row discount, tax, subtotal, and final row total through the canonical POS totals calculator in minor units. Existing row discounts MUST be applied exactly once and tax MUST follow existing POS inclusive-tax rules. On a later quantity change, the authoritative unit price SHALL remain unchanged and the row's canonical totals SHALL be recalculated for the new quantity, including when the row has a conversion or customer tier.

#### Scenario: Row discount applies once
- **WHEN** a row with a fixed row discount receives an approved unit price
- **THEN** the row gross MUST be the requested unit price multiplied by effective quantity
- **AND** the row discount MUST be subtracted exactly once

#### Scenario: Quantity change preserves explicit unit price
- **WHEN** a cashier overrides the unit price of a conversion-capable row for a tier customer and then changes its quantity
- **THEN** the overridden unit price MUST remain unchanged
- **AND** the new row total MUST be calculated from that price and the new quantity
- **AND** automatic conversion and tier prices MUST NOT replace it
