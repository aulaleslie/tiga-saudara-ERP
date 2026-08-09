## MODIFIED Requirements

### Requirement: Sales users can set a final line total
The Sales cart SHALL provide an editable final line total for each standard non-bundled line. The line total SHALL represent the amount after line discount and applicable tax and before global discount and shipping. A valid committed Total Baris SHALL be the authoritative row `sub_total` to two monetary decimals, even when the derived two-decimal unit price multiplied by quantity differs from that total.

A tax SHALL be considered applicable to a Sales line only when the effective business is PKP. When the effective business is not PKP, a Sales line SHALL carry no tax regardless of any tax selection held on that line, and the reverse calculation SHALL NOT remove a tax amount from the committed Total Baris.

#### Scenario: Valid line total derives unit price
- **WHEN** a user commits a valid Total Baris value for a standard Sales line
- **THEN** the system SHALL reverse applicable tax inclusion and line discount to calculate the two-decimal unit price
- **AND** the system SHALL mark the line as manually priced
- **AND** the resulting row `sub_total` SHALL equal the committed Total Baris exactly
- **AND** the system SHALL recompute document totals using that authoritative row total

#### Scenario: Non-divisible total does not drift after unit-price rounding
- **WHEN** a user commits Total Baris Rp1.460.000 for a standard non-PKP Sales row with quantity 1.200
- **THEN** the row `sub_total` SHALL equal Rp1.460.000, not Rp1.460.004
- **AND** the row pre-tax subtotal SHALL equal Rp1.460.000
- **AND** the row tax amount SHALL equal Rp0

#### Scenario: Invalid final line total is rejected
- **WHEN** a user commits a blank, nonnumeric, or negative Total Baris value
- **THEN** the system SHALL reject the value and retain the prior calculated line total

#### Scenario: Full percentage discount rejects a nonzero total
- **WHEN** a Sales line has a 100 percent percentage line discount
- **AND** a user commits a nonzero Total Baris value
- **THEN** the system SHALL reject the value and retain the prior calculated line total

#### Scenario: Non-PKP line total preserves the committed amount despite a retained tax selection
- **WHEN** the effective business is not PKP
- **AND** a standard Sales line still holds a tax selection retained from an earlier PKP context or business change
- **AND** a user commits a valid Total Baris value
- **THEN** the system SHALL NOT divide the committed amount by that tax rate
- **AND** the resulting line total SHALL equal the committed Total Baris value
- **AND** the line's tax amount SHALL be zero
- **AND** the line's pre-tax subtotal SHALL equal the committed Total Baris value

#### Scenario: PKP tax-exclusive line total preserves its final committed amount
- **WHEN** the effective business is PKP with tax exclusion in effect
- **AND** a standard Sales line has an applicable tax selected
- **AND** a user commits a valid Total Baris value whose derived unit price is not representable at two decimals
- **THEN** the system SHALL reverse that tax rate to derive the unit price
- **AND** the row `sub_total` SHALL equal the committed Total Baris exactly
- **AND** the line's pre-tax subtotal plus the line's tax amount SHALL equal the committed Total Baris exactly

#### Scenario: PKP tax-inclusive line total retains the committed amount
- **WHEN** the effective business is PKP with tax inclusion in effect
- **AND** a standard Sales line has an applicable tax selected
- **AND** a user commits a valid Total Baris value whose derived unit price is not representable at two decimals
- **THEN** the row `sub_total` SHALL equal the committed Total Baris exactly
- **AND** the system SHALL extract the tax portion from that amount
- **AND** the line's pre-tax subtotal plus the line's tax amount SHALL equal the committed Total Baris exactly
