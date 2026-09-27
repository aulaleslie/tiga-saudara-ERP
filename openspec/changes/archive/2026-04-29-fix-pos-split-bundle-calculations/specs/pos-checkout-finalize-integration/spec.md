## ADDED Requirements

### Requirement: Accurate Split Ownership for Bundles

#### Scenario: Group owns only bundle components
- **WHEN** a POS checkout is split and a group owns bundle components but 0 units of the parent product.
- **THEN** the resulting SaleDetail row for the parent product must have quantity 0 and unit price 0.
- **AND** the SaleDetail row must have a subtotal equal to the sum of its owned bundle components.
- **AND** the parent row must be marked as not stock managed to avoid duplicate inventory deductions.
