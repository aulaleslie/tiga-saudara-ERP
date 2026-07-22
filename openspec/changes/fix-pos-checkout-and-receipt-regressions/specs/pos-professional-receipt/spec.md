## ADDED Requirements

### Requirement: Thermal receipt monetary values SHALL remain intact
The 72 mm POS receipt SHALL reserve a right-aligned monetary column for line totals, grand total, payment amounts, and change. A formatted monetary value MUST remain on one line without clipping, truncation, or breaking between digit groups; descriptive labels and product names SHALL absorb wrapping before monetary values do.

#### Scenario: Large grand total fits the receipt
- **WHEN** the receipt contains a grand total with enough digits to compete with the label for horizontal space
- **THEN** the entire formatted amount remains visible on one right-aligned line within the printable receipt width

#### Scenario: Large line total preserves all digits
- **WHEN** an item has a large formatted line total
- **THEN** the amount is not split across lines and no digit or thousands separator is hidden

#### Scenario: Large payment and change values remain aligned
- **WHEN** payment or change rows contain large formatted amounts
- **THEN** each complete value remains right-aligned and legible without forcing the receipt wider than its configured print width

#### Scenario: Long description competes with monetary value
- **WHEN** a product name or payment-method label is long
- **THEN** the descriptive content wraps as needed while the corresponding monetary value remains intact
