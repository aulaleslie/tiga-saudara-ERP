## ADDED Requirements

### Requirement: Purchase rows accept a final line total
The purchase create and edit product cart SHALL provide an editable `Total Baris` for every selected product. The entered value SHALL represent the post-line-discount, tax-inclusive total for that row before header-level discount and shipping.

#### Scenario: User enters a final total for a tax-included line
- **WHEN** a user enters a valid final total for a row whose tax mode is included
- **THEN** the system SHALL derive a compatible unit price and recalculate DPP, tax amount, and subtotal
- **AND** the row subtotal SHALL equal the entered final total subject only to monetary rounding

#### Scenario: User enters a final total for a tax-excluded line
- **WHEN** a user enters a valid final total for a row whose tax mode is excluded and has a selected tax
- **THEN** the system SHALL derive the pre-tax unit price from the final total
- **AND** the recalculated subtotal SHALL include the selected tax and equal the entered final total subject only to monetary rounding

#### Scenario: Header adjustments are excluded from the line total
- **WHEN** a user enters a final row total and the purchase has global discount or shipping
- **THEN** the system SHALL not allocate either header adjustment into that row
- **AND** the purchase grand total SHALL continue applying the existing header adjustment behavior after all row subtotals are calculated

### Requirement: Final total preserves tax and discount consistency
The system SHALL use the existing quantity, selected line tax, tax-inclusion mode, and line discount type/input to reverse-calculate a unit price and then use the canonical line calculation for persisted subtotal, DPP, and tax values.

#### Scenario: Fixed discount is preserved
- **WHEN** a row with a fixed per-unit discount receives a final-total input
- **THEN** the stored unit price SHALL be adjusted so the existing fixed discount remains in effect
- **AND** the recalculated subtotal SHALL match the requested final total subject only to monetary rounding

#### Scenario: Percentage discount is preserved
- **WHEN** a row with a percentage discount receives a final-total input
- **THEN** the stored unit price SHALL be reverse-calculated so the existing percentage discount remains in effect
- **AND** the recalculated subtotal SHALL match the requested final total subject only to monetary rounding

#### Scenario: Invalid final total is rejected
- **WHEN** a user enters a missing, non-numeric, or negative final line total
- **THEN** the system SHALL show validation feedback in Bahasa Indonesia
- **AND** it SHALL not persist contradictory line pricing values

