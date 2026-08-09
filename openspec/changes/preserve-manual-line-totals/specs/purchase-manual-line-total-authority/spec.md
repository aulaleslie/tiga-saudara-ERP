## ADDED Requirements

### Requirement: Purchase manual line total remains authoritative
The Purchase cart SHALL allow a user to commit a valid final `Total Baris` for a standard non-bundled row. The cart SHALL persist the committed total as the row `sub_total` to two monetary decimals, even when the derived two-decimal unit price multiplied by quantity differs from that total.

#### Scenario: Non-PKP non-divisible line total is preserved
- **WHEN** a user in a non-PKP Purchase cart commits Total Baris Rp1.460.000 for a standard row with quantity 1.200
- **THEN** the row `sub_total` SHALL equal Rp1.460.000, not Rp1.460.004
- **AND** the row pre-tax subtotal SHALL equal Rp1.460.000
- **AND** the row tax amount SHALL equal Rp0

#### Scenario: Rounded derived unit price does not overwrite purchase total
- **WHEN** the committed Purchase total divided by the row quantity requires more than two decimal places per unit
- **THEN** the system SHALL store/display a two-decimal derived unit price
- **AND** it SHALL NOT recompute the committed row total from that rounded unit price during the same total-edit event

### Requirement: Purchase manual line total tax allocation reconciles
For a PKP Purchase cart row with an applicable tax, the system SHALL allocate pre-tax subtotal and tax from the committed final Total Baris so their two-decimal sum equals the committed row total exactly.

#### Scenario: PKP tax-included non-divisible total is preserved
- **WHEN** a user commits a valid non-divisible Total Baris to a standard Purchase row in a PKP cart with tax inclusion and an applicable tax
- **THEN** the row `sub_total` SHALL equal the committed Total Baris exactly
- **AND** `sub_total_before_tax + product_tax_amount` SHALL equal the committed Total Baris exactly

#### Scenario: PKP tax-exclusive non-divisible total is preserved
- **WHEN** a user commits a valid non-divisible Total Baris to a standard Purchase row in a PKP cart with tax exclusion and an applicable tax
- **THEN** the row `sub_total` SHALL equal the committed Total Baris exactly
- **AND** `sub_total_before_tax + product_tax_amount` SHALL equal the committed Total Baris exactly

### Requirement: Purchase manual total edit safeguards remain intact
The Purchase cart SHALL retain the existing validation and row eligibility rules for manual line-total edits.

#### Scenario: Invalid or excluded Purchase row does not change
- **WHEN** a user commits a blank, nonnumeric, negative, or disallowed line total to a Purchase row
- **THEN** the system SHALL retain the prior row monetary values
- **AND** it SHALL retain the existing validation or row-editability behavior
