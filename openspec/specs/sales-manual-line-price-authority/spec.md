# sales-manual-line-price-authority Specification

## Purpose

Manual Sales line-total edits preserve the exact committed total across non-PKP and PKP tax modes despite two-decimal unit-price rounding. The system persists pricing sources that distinguish automatic tier-derived pricing from manually committed unit-price or line-total edits.

## Requirements

### Requirement: Sales lines retain durable pricing authority
The system SHALL persist a pricing source for each standard Sales detail that distinguishes automatic tier-derived pricing from a manually committed unit-price or line-total edit. New standard Sales lines SHALL begin as automatic. Existing Sales details present when this capability is deployed SHALL be treated as manually priced so they are not silently repriced during later edits.

#### Scenario: Manual unit-price edit is durable
- **WHEN** a user commits an edit to a standard Sales line's unit price
- **THEN** the system SHALL mark that line as manually priced even when the entered numeric value equals its prior value
- **AND** the line SHALL remain manually priced after the sale is saved and reopened for editing

#### Scenario: Legacy sale detail is edited after deployment
- **WHEN** a user opens a sale containing a detail created before pricing-source persistence existed
- **THEN** the hydrated line SHALL be treated as manually priced
- **AND** customer or draft-business changes SHALL not replace its unit price

### Requirement: Sales users can set a final line total
The Sales cart SHALL provide an editable final line total for each standard non-bundled line. The line total SHALL represent the final tax-inclusive amount after line discount and applicable tax and before global discount and shipping, regardless of whether the document tax setting is tax-included or tax-exclusive. A valid committed Total Baris SHALL be the authoritative row `sub_total` to two monetary decimals, even when the derived two-decimal unit price multiplied by quantity differs from that total. The system SHALL NOT add tax on top of a committed Total Baris in tax-exclusive mode.

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

### Requirement: Stored Sales manual total survives edit and reload
When a stored Sale is eligible for editing, its persisted authoritative line subtotal and tax allocation SHALL be hydrated into the edit cart and retained after a user changes Total Baris, saves the Sale, and opens it for editing again.

#### Scenario: Stored PKP Sale is edited and reopened
- **WHEN** a user opens an eligible stored PKP Sale with a standard row of quantity 1.200
- **AND** changes that row's Total Baris to Rp1.460.000
- **AND** saves and reopens the Sale for editing
- **THEN** the persisted and rehydrated row `sub_total` SHALL equal Rp1.460.000 exactly
- **AND** the rehydrated `sub_total_before_tax + product_tax_amount` SHALL equal Rp1.460.000 exactly
- **AND** the row SHALL retain its `manual_line_total` pricing source

#### Scenario: Stored non-PKP Sale is edited and reopened
- **WHEN** a user opens an eligible stored non-PKP Sale with a standard row of quantity 1.200
- **AND** changes that row's Total Baris to Rp1.460.000
- **AND** saves and reopens the Sale for editing
- **THEN** the persisted and rehydrated row `sub_total` and pre-tax subtotal SHALL equal Rp1.460.000 exactly
- **AND** the persisted and rehydrated tax amount SHALL equal Rp0

### Requirement: Manual price remains authoritative during recalculation
The system SHALL retain the unit price of a manually priced standard Sales line during customer selection, customer changes, quantity changes, line discount changes, tax changes, tax-inclusion changes, and draft-business changes. Derived line totals SHALL still be recomputed when the relevant quantity, discount, or tax inputs change.

#### Scenario: Customer changes after manual price entry
- **WHEN** a standard Sales line has a manually committed unit-price or Total Baris edit
- **AND** the user selects a customer with a different tier
- **THEN** the line's unit price SHALL remain unchanged
- **AND** other automatic eligible lines MAY reprice normally

#### Scenario: Business changes tax context for manual line
- **WHEN** a manually priced standard Sales line is moved with a draft sale from one PKP context to another
- **THEN** the line's unit price SHALL remain unchanged
- **AND** tax-derived values and document totals SHALL be recalculated for the target context
