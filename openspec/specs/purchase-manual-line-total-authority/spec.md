# purchase-manual-line-total-authority Specification

## Purpose

Purchase cart rows preserve a valid manually committed total and allocate any applicable tax without precision drift.

## Requirements

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
For a PKP Purchase cart row with an applicable tax, Total Baris SHALL always be the final tax-inclusive amount entered by the user, whether the Purchase document is tax-included or tax-exclusive. The system SHALL allocate pre-tax subtotal and tax from that committed final total so their two-decimal sum equals the committed row total exactly. It SHALL NOT add tax on top of the committed Total Baris in tax-exclusive mode.

#### Scenario: PKP tax-included non-divisible total is preserved
- **WHEN** a user commits a valid non-divisible Total Baris to a standard Purchase row in a PKP cart with tax inclusion and an applicable tax
- **THEN** the row `sub_total` SHALL equal the committed Total Baris exactly
- **AND** `sub_total_before_tax + product_tax_amount` SHALL equal the committed Total Baris exactly

#### Scenario: PKP tax-exclusive non-divisible total is preserved
- **WHEN** a user commits a valid non-divisible Total Baris to a standard Purchase row in a PKP cart with tax exclusion and an applicable tax
- **THEN** the row `sub_total` SHALL equal the committed Total Baris exactly
- **AND** `sub_total_before_tax + product_tax_amount` SHALL equal the committed Total Baris exactly

### Requirement: Stored Purchase manual total survives edit and reload
When a stored Purchase is eligible for editing, its persisted authoritative line subtotal and tax allocation SHALL be hydrated into the edit cart and retained after a user changes Total Baris, saves the document, and opens it for editing again.

#### Scenario: Stored PKP Purchase is edited and reopened
- **WHEN** a user opens an eligible stored PKP Purchase with a standard row of quantity 1.200
- **AND** changes that row's Total Baris to Rp1.460.000
- **AND** saves and reopens the Purchase for editing
- **THEN** the persisted and rehydrated row `sub_total` SHALL equal Rp1.460.000 exactly
- **AND** the rehydrated `sub_total_before_tax + product_tax_amount` SHALL equal Rp1.460.000 exactly

#### Scenario: Stored non-PKP Purchase is edited and reopened
- **WHEN** a user opens an eligible stored non-PKP Purchase with a standard row of quantity 1.200
- **AND** changes that row's Total Baris to Rp1.460.000
- **AND** saves and reopens the Purchase for editing
- **THEN** the persisted and rehydrated row `sub_total` and pre-tax subtotal SHALL equal Rp1.460.000 exactly
- **AND** the persisted and rehydrated tax amount SHALL equal Rp0

### Requirement: Purchase manual total edit safeguards remain intact
The Purchase cart SHALL retain the existing validation and row eligibility rules for manual line-total edits.

#### Scenario: Invalid or excluded Purchase row does not change
- **WHEN** a user commits a blank, nonnumeric, negative, or disallowed line total to a Purchase row
- **THEN** the system SHALL retain the prior row monetary values
- **AND** it SHALL retain the existing validation or row-editability behavior

### Requirement: Saved Purchase manual line total keeps its derived unit price
When a Purchase row is saved after a user committed a manual Total Baris, the saved entered unit price SHALL be the price derived from that committed total (before any discount, rounded to two monetary decimals), not the unit price in effect before the total was committed. The saved canonical unit price SHALL be derived from that entered unit price and the row conversion factor.

#### Scenario: Create with overridden line total stores the derived unit price
- **WHEN** a user creates a Purchase with a row priced Rp1.200.000, quantity 2, no discount, and commits Total Baris Rp2.157.350
- **THEN** the saved purchase detail entered unit price SHALL equal Rp1.078.675
- **AND** the saved canonical unit price SHALL equal Rp1.078.675
- **AND** the saved row `sub_total` SHALL equal Rp2.157.350
- **AND** the Purchase detail page SHALL display unit price Rp1.078.675 for that row

#### Scenario: Edit with overridden line total stores the derived unit price
- **WHEN** a user edits an existing Purchase and commits a new Total Baris on a row whose saved unit price is Rp1.200.000 with quantity 2
- **THEN** after saving, the purchase detail entered unit price SHALL equal the new total divided by 2, rounded to two decimals
- **AND** it SHALL NOT remain Rp1.200.000

#### Scenario: Overridden line total with fixed discount stores the pre-discount unit price
- **WHEN** a user commits Total Baris Rp2.000.000 on a row with quantity 2 and a fixed per-unit discount of Rp50.000 in a tax-included Purchase without tax
- **THEN** the saved entered unit price SHALL equal Rp1.050.000
- **AND** the saved row `sub_total` SHALL equal Rp2.000.000

#### Scenario: Non-divisible overridden line total keeps the committed total
- **WHEN** a user commits Total Baris Rp1.000.000 on a row with quantity 3
- **THEN** the saved entered unit price SHALL equal Rp333.333,33
- **AND** the saved row `sub_total` SHALL equal Rp1.000.000

