## MODIFIED Requirements

### Requirement: Sales users can set a final line total
The Sales cart SHALL provide an editable final line total for each standard non-bundled line. The line total SHALL represent the amount after line discount and applicable tax and before global discount and shipping. Before a user commits an edit, opening this control SHALL populate it with the full current authoritative raw line total corresponding to the collapsed formatted line total.

A tax SHALL be considered applicable to a Sales line only when the effective business is PKP. When the effective business is not PKP, a Sales line SHALL carry no tax regardless of any tax selection held on that line, and the reverse calculation SHALL NOT remove a tax amount from the committed Total Baris.

#### Scenario: Sales editor opens with full current line total
- **WHEN** a standard non-bundled Sales line has a current final line total of `46500`
- **AND** a user opens its `Total Baris` editor before making any edit
- **THEN** the editor SHALL display `46500`
- **AND** it SHALL NOT display a stale or truncated value such as `4650`

#### Scenario: Valid line total derives unit price
- **WHEN** a user commits a valid Total Baris value for a standard Sales line
- **THEN** the system SHALL reverse applicable tax inclusion and line discount to calculate the two-decimal unit price
- **AND** the system SHALL mark the line as manually priced
- **AND** the system SHALL recompute line and document totals through the normal Sales calculation path

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

#### Scenario: PKP tax-exclusive line total reverses the applicable tax
- **WHEN** the effective business is PKP with tax exclusion in effect
- **AND** a standard Sales line has an applicable tax selected
- **AND** a user commits a valid Total Baris value
- **THEN** the system SHALL reverse that tax rate to derive the unit price
- **AND** the line's pre-tax subtotal plus the line's tax amount SHALL equal the committed Total Baris value

#### Scenario: PKP tax-inclusive line total retains the committed amount
- **WHEN** the effective business is PKP with tax inclusion in effect
- **AND** a standard Sales line has an applicable tax selected
- **AND** a user commits a valid Total Baris value
- **THEN** the resulting line total SHALL equal the committed Total Baris value
- **AND** the system SHALL extract the tax portion from that amount
- **AND** the line's pre-tax subtotal plus the line's tax amount SHALL equal the committed Total Baris value
