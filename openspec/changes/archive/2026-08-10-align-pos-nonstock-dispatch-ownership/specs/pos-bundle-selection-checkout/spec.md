## MODIFIED Requirements

### Requirement: POS checkout SHALL apply stock validation, dispatch auditing, and deduction per product based on stock-managed behavior
When a selected bundle is posted through POS checkout, the parent product and each bundled child product SHALL independently follow rules based on that product's persisted `stock_managed` flag. Stock-managed content SHALL use normal allocation, stock validation, immediate approved dispatch, and stock deduction. Non-stock-managed content SHALL use the first configured POS sales-location source for financial and approved audit DispatchDetail ownership, without stock validation or inventory mutation.

#### Scenario: Stock-managed parent and child products both deduct stock
- **WHEN** POS checkout finalizes a selected bundle whose parent product and bundled child product both have `stock_managed = true`
- **THEN** checkout validates stock sufficiency for the parent product and the bundled child product
- **AND** checkout deducts stock for both products

#### Scenario: Non-stock-managed bundled child creates audit detail without stock deduction
- **WHEN** POS checkout finalizes a selected bundle containing a bundled child product with `stock_managed = false`
- **THEN** checkout SHALL create an approved audit DispatchDetail for that child under the first configured POS sales-location source
- **AND** checkout MUST NOT require stock availability or create stock deduction for that child product

#### Scenario: Non-stock-managed parent and stock-managed child split responsibilities
- **WHEN** POS checkout finalizes a selected bundle whose parent product has `stock_managed = false` and a bundled child product has `stock_managed = true`
- **THEN** the parent SHALL use the first configured POS source and receive an approved audit DispatchDetail without inventory effects
- **AND** the child SHALL use its normal stock allocation and receive normal immediate dispatch and stock deduction
- **AND** the child quantity deducted SHALL equal sold bundle quantity multiplied by its quantity per bundle
