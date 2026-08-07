## MODIFIED Requirements

### Requirement: Atomic owner price and stock snapshot synchronization
For each valid matched owner/product target, the system SHALL synchronize the three selling tiers and owner-location stock snapshot in one database transaction. Product matching SHALL use the shared canonical catalog identity and this workflow SHALL never create a product.

#### Scenario: Valid matched row updates both effects
- **WHEN** a row resolves to an existing product through the shared canonical identity, owner setting, owner location, positive selling price, and signed stock value
- **THEN** the system SHALL set that owner's `sale_price`, `tier_1_price`, and `tier_2_price` to `SellPrice`
- **AND** the system SHALL replace the resolved product/location stock with `Stock`
- **AND** the system SHALL commit both effects atomically

#### Scenario: Stock persistence fails
- **WHEN** an exception occurs while applying a valid row's stock snapshot or adjustment transaction
- **THEN** every price and stock mutation for that target SHALL roll back
- **AND** the row SHALL be marked as an error without partial selling-tier or inventory changes

#### Scenario: Unmatched or conflicted product is skipped
- **WHEN** neither product code nor the shared canonical product identity resolves exactly one existing product
- **THEN** the system SHALL mark the row as skipped or error with an actionable unmatched or ambiguous-product reason
- **AND** the system SHALL NOT create a product, unit, price row, stock row, or transaction

