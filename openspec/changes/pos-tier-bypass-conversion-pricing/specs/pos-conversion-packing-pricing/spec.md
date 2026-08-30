## MODIFIED Requirements

### Requirement: Per-group cheapest-of packing pricing

For a line whose product has a box conversion and whose selected customer is neither `WHOLESALER` nor `RESELLER`, the system SHALL price the quantity by decomposing it into full box groups and a loose remainder. For each full group of `factor` base units the system SHALL charge the cheaper of the box price versus `factor × base-unit price`. The remainder SHALL be priced as loose base units at the base-unit price. For a `WHOLESALER` customer, the system SHALL bypass the box price and charge every base unit at the resolved `tier_1_price`. For a `RESELLER` customer, the system SHALL bypass the box price and charge every base unit at the resolved `tier_2_price`. If the applicable tier price falls back under existing product-price rules, that fallback SHALL remain a base-unit price and MUST NOT use the conversion price.

#### Scenario: Non-tier customer receives packed conversion pricing
- **WHEN** base-unit price is 8000, box factor is 12, box price is 85000, no tier customer is selected, and quantity is 12
- **THEN** the full group is priced at `min(85000, 12 × 8000)`
- **AND** the authoritative line total is `85000.00`

#### Scenario: Wholesaler bypasses a cheaper conversion price
- **WHEN** a wholesaler has a resolved `tier_1_price` of 7500, box factor is 12, box price is 85000, and quantity is 12
- **THEN** the conversion price is not considered
- **AND** the authoritative line total is `12 × 7500 = 90000.00`

#### Scenario: Reseller bypasses conversion price with decimal tier total
- **WHEN** a reseller has a resolved `tier_2_price` of 6583.33, box factor is 12, box price is 85000, and quantity is 12
- **THEN** the conversion price is not considered
- **AND** the authoritative line total is `12 × 6583.33 = 78999.96`

#### Scenario: Tier-price fallback still bypasses conversion pricing
- **WHEN** a wholesaler or reseller tier price is absent and existing product-price rules resolve the base sale price as the fallback
- **THEN** every base unit is charged at that resolved base-unit fallback
- **AND** the conversion price is not considered

#### Scenario: Non-tier loose base units below one full box
- **WHEN** base-unit price is 45000, box factor is 5, and quantity is 3
- **THEN** no box group is formed
- **AND** the line total is `3 × 45000 = 135000`

### Requirement: Reprice on quantity and customer-tier change

The system SHALL re-price a mutable packed line on every quantity change and every customer change. For a non-tier customer it SHALL recompute the line total from the conversion packing rules. For a `WHOLESALER` or `RESELLER` customer it SHALL recompute the line total exclusively from base-unit quantity and the applicable resolved tier price. Line price SHALL NOT remain frozen at the price applicable before the change.

#### Scenario: Non-tier quantity change updates conversion packing
- **WHEN** a non-tier packed line has quantity 5 priced at 210000 and the cashier changes quantity to 6 with base price 45000
- **THEN** the line total is recomputed to 255000

#### Scenario: Selecting a reseller replaces an existing conversion total
- **WHEN** a packed line has quantity 12 and total 85000 for a non-tier customer
- **AND** the cashier selects a reseller whose `tier_2_price` is 6583.33
- **THEN** the line total is recomputed to 78999.96
- **AND** the previous conversion total is not retained

#### Scenario: Selecting a wholesaler replaces an existing conversion total
- **WHEN** a packed line has a conversion-derived total for a non-tier customer
- **AND** the cashier selects a wholesaler with a valid `tier_1_price`
- **THEN** the line total is recomputed as quantity multiplied by `tier_1_price`
- **AND** no box price participates in the result

#### Scenario: Clearing a tier customer restores non-tier packing
- **WHEN** a packed line is priced exclusively from a wholesaler or reseller tier
- **AND** the cashier clears the selected customer
- **THEN** the line is recomputed using the non-tier conversion packing rules

### Requirement: Blended-line storage with authoritative line total

The system SHALL store a packed line as a single line whose exact minor-unit line total is authoritative and whose `unit_price` is display-oriented. Cart totals SHALL be derived from the authoritative line total so that they tie out exactly, independent of blended-unit-price rounding. Tier-customer lines that retain packed-line identity SHALL use the exact `quantity × two-decimal tier price` result as their authoritative line total.

#### Scenario: Tier total retains two-decimal precision
- **WHEN** a reseller line has quantity 12 and tier unit price 6583.33
- **THEN** its authoritative line total is 78999.96
- **AND** cart totals are not reconstructed from a rounded integer or blended display price

#### Scenario: Non-tier blended price does not corrupt the total
- **WHEN** a packed line has an authoritative line total of 100000 and quantity 3 with blended unit price 33333.33
- **THEN** the cart subtotal contribution is exactly 100000
- **AND** it is not recalculated as 99999.99
