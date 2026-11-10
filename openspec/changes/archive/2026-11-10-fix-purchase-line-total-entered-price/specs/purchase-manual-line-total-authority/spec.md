# Spec Delta

## ADDED Requirements

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
