## ADDED Requirements

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
The Sales cart SHALL provide an editable final line total for each standard non-bundled line. The line total SHALL represent the amount after line discount and applicable tax and before global discount and shipping.

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
