## MODIFIED Requirements

### Requirement: Base-unit quantity tracking

The system SHALL track POS cart line quantity in the product's base unit. When a line is added by scanning a sales-enabled conversion (box) barcode in the acting business, the system SHALL set the initial quantity to the conversion factor (number of base units per box) and record the conversion as a packing hint on the line.

#### Scenario: Scanning a box barcode adds factor base units
- **WHEN** a cashier scans the box barcode of a product whose box conversion factor is 5
- **THEN** the line quantity is set to 5 base units
- **AND** the read-only breakdown shows "1 box"

#### Scenario: Quantity is always expressed in base units
- **WHEN** a line for a product with a box conversion has quantity 6
- **THEN** the quantity 6 represents 6 base units (not 6 boxes)

#### Scenario: Disabled box scan does not mutate cart
- **WHEN** a cashier scans a sales-disabled conversion barcode in the acting business
- **THEN** the scan SHALL be rejected before adding or incrementing a line
- **AND** scan/search fallback SHALL NOT add the base product instead


### Requirement: Per-group cheapest-of packing pricing

For a line whose captured pricing basis includes a sales-enabled box conversion, the system SHALL price the quantity by decomposing it into full box groups and a loose remainder. For each full group of `factor` base units the system SHALL charge the cheaper of the box price versus `factor × tier base-unit price`. The remainder (fewer than `factor` base units) SHALL be priced as loose base units at the tier base-unit price. Each box group SHALL be evaluated independently. The line total SHALL be the sum of all group and remainder charges.

#### Scenario: Non-tier customer, quantity crosses one box plus remainder
- **WHEN** base-unit price is 45000, box factor is 5, box price is 210000, no customer tier, and quantity is 6
- **THEN** the box group is priced at min(210000, 5×45000=225000) = 210000
- **AND** the remainder of 1 is priced at 45000
- **AND** the line total is 255000

#### Scenario: Reseller tier prioritized when cheaper
- **WHEN** reseller base-unit price is 42000, box factor is 5, box price is 210000, and quantity is 6
- **THEN** the box group is priced at min(210000, 5×42000=210000) = 210000
- **AND** the remainder of 1 is priced at 42000
- **AND** the line total is 252000

#### Scenario: Loose base units below one full box
- **WHEN** base-unit price is 45000, box factor is 5, and quantity is 3
- **THEN** no box group is formed
- **AND** the line total is 3 × 45000 = 135000

### Requirement: Packing applies to any line whose product has a box conversion

The system SHALL capture `pricing_basis` including the box candidate for any stock-managed product line whose product has a sales-enabled box conversion in the acting business, regardless of whether the line was entered via box barcode or via product search. Packing SHALL therefore be considered on such lines even when they were not added by scanning the box barcode.

#### Scenario: Product-search line still benefits from box packing
- **WHEN** a product with a box conversion is added via product search (not box scan) and quantity is set to 6 (non-tier)
- **THEN** the line is priced with box packing to a line total of 255000

#### Scenario: Disabled box is omitted from new pricing basis
- **WHEN** a product is added via ordinary product search while BOX is sales-disabled
- **THEN** the captured pricing basis SHALL exclude BOX
- **AND** existing normal pricing and other eligible conversion behavior SHALL apply

#### Scenario: Toggle does not rewrite an existing cached basis
- **WHEN** BOX is disabled after a POS line captured its pricing basis
- **THEN** the existing line SHALL retain its frozen pricing basis and zero-query repricing contract
- **AND** subsequent new BOX scans SHALL be rejected using the current business flag

