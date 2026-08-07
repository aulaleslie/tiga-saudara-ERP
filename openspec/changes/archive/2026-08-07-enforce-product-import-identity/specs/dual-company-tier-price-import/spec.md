## MODIFIED Requirements

### Requirement: Rows update independent company-scoped selling tiers
For every valid row, the system SHALL resolve exactly one existing product by the shared canonical catalog identity derived from `Nama Produk` and update only that product's existing `product_prices` row for the row's worksheet company. `Harga Jual`, `Harga Tier 1`, and `Harga Tier 2` SHALL be parsed and applied independently; owner markers, session setting, product stock, purchase costs, taxes, legacy product prices, bundle prices, and conversion prices SHALL not affect or be affected by this workflow.

#### Scenario: A row updates all three tiers
- **WHEN** a row has a uniquely matched product and numeric values in all three selling-tier columns
- **THEN** the system SHALL set that worksheet company's sale, Tier 1, and Tier 2 prices to their respective imported values in one transaction
- **AND** the system SHALL not alter another company's price row for the product

#### Scenario: A row updates selected tiers only
- **WHEN** a uniquely matched row has one or two blank selling-tier cells and at least one numeric selling-tier value
- **THEN** the system SHALL preserve every tier represented by a blank cell
- **AND** the system SHALL update only the tiers represented by numeric cells

#### Scenario: Zero is an explicit selling price
- **WHEN** a selling-tier cell contains numeric zero
- **THEN** the system SHALL store zero for that specific tier

#### Scenario: No selling tier is supplied
- **WHEN** all three selling-tier cells are blank
- **THEN** the system SHALL skip the row with an explanatory result
- **AND** the system SHALL not change its price row

#### Scenario: Product cannot be resolved uniquely
- **WHEN** a row's canonical product identity matches zero or more than one catalog product
- **THEN** the system SHALL mark the row as skipped or error with an actionable match reason
- **AND** the system SHALL not create a product or price row

#### Scenario: Target company has no existing price row
- **WHEN** the matched product has no `product_prices` row for the worksheet company
- **THEN** the system SHALL mark the row as skipped or error with an actionable reason
- **AND** the system SHALL not create a price row

