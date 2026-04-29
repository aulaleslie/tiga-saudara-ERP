## ADDED Requirements

### Requirement: Persistence of Bundle Item Revenue

#### Scenario: Bundle item pricing in split sales
- **WHEN** a SaleBundleItem is created during a POS split checkout.
- **THEN** it must be persisted with the actual price and subtotal allocated to that owner.
- **AND** the bundle item quantity must reflect the actual quantity provided by that owner.
- **AND** the "Sales Show" view must display these prices and subtotals accurately.
