## ADDED Requirements

### Requirement: Base-unit quantity tracking

The system SHALL track POS cart line quantity in the product's base unit. When a line is added by scanning a conversion (box) barcode, the system SHALL set the initial quantity to the conversion factor (number of base units per box) and record the conversion as a packing hint on the line.

#### Scenario: Scanning a box barcode adds factor base units
- **WHEN** a cashier scans the box barcode of a product whose box conversion factor is 5
- **THEN** the line quantity is set to 5 base units
- **AND** the read-only breakdown shows "1 box"

#### Scenario: Quantity is always expressed in base units
- **WHEN** a line for a product with a box conversion has quantity 6
- **THEN** the quantity 6 represents 6 base units (not 6 boxes)

### Requirement: Per-group cheapest-of packing pricing

For a line whose product has a box conversion, the system SHALL price the quantity by decomposing it into full box groups and a loose remainder. For each full group of `factor` base units the system SHALL charge the cheaper of the box price versus `factor × tier base-unit price`. The remainder (fewer than `factor` base units) SHALL be priced as loose base units at the tier base-unit price. Each box group SHALL be evaluated independently. The line total SHALL be the sum of all group and remainder charges.

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

### Requirement: Reprice on quantity and customer-tier change

The system SHALL re-pack and re-price a line on every quantity change and on every customer-tier change, recomputing the line total from the packing rules. Line price SHALL NOT remain frozen at add time.

#### Scenario: Quantity change updates the price
- **WHEN** a packed line has quantity 5 priced at 210000 and the cashier changes quantity to 6 (non-tier, base price 45000)
- **THEN** the line total is recomputed to 255000

#### Scenario: Selecting a reseller customer reprices packed lines
- **WHEN** a packed line of quantity 6 is priced at 255000 for a non-tier customer and a reseller customer (base-unit price 42000) is then selected
- **THEN** the line total is recomputed to 252000

### Requirement: Blended-line storage with authoritative line total

The system SHALL store a packed line as a single line whose `line_total` is authoritative (integer minor units) and whose `unit_price` is a display-only blended value equal to `line_total / qty`. Cart totals SHALL be derived from the authoritative `line_total` so that displayed totals tie out exactly, independent of blended-price rounding.

#### Scenario: Totals derive from authoritative line total
- **WHEN** a packed line has an authoritative line total of 255000 and quantity 6 (blended unit price 42500)
- **THEN** the cart subtotal contribution for that line is exactly 255000

#### Scenario: Blended unit price that does not divide evenly does not corrupt the total
- **WHEN** a packed line has an authoritative line total of 100000 and quantity 3 (blended unit price rounds to 33333.33)
- **THEN** the cart subtotal contribution for that line is exactly 100000, not 99999.99

### Requirement: Cached pricing basis with zero-DB re-pricing

The system SHALL capture all pricing inputs required for packing (conversion factor, box price, base-unit price, tier prices, and tax identity/rate) onto the cart line once, at add or scan time, as a `pricing_basis`. Subsequent quantity changes and customer-tier changes SHALL re-pack using the cached `pricing_basis` without issuing additional database queries. Prices SHALL be frozen at scan time; the system SHALL NOT re-query pricing mid-cart.

#### Scenario: Quantity update issues no pricing queries
- **WHEN** a packed line's quantity is changed
- **THEN** the line is re-packed using the cached `pricing_basis`
- **AND** no database query is issued to resolve the conversion, product, or tax price

#### Scenario: Admin price change mid-cart does not affect an existing line
- **WHEN** a line is added with a cached box price of 210000 and an administrator later changes the box price to 220000
- **THEN** subsequent quantity changes on that line re-pack against the cached 210000

### Requirement: Packing applies to any line whose product has a box conversion

The system SHALL capture `pricing_basis` including the box candidate for any stock-managed product line whose product has a box conversion, regardless of whether the line was entered via box barcode or via product search. Packing SHALL therefore be considered on such lines even when they were not added by scanning the box barcode.

#### Scenario: Product-search line still benefits from box packing
- **WHEN** a product with a box conversion is added via product search (not box scan) and quantity is set to 6 (non-tier)
- **THEN** the line is priced with box packing to a line total of 255000
