# Spec Delta

## ADDED Requirements

### Requirement: Transaction details SHALL display total bundle component quantities
The POS transaction detail page SHALL display each bundle component's exact total quantity for the parent quantity sold. Snapshot fallback composition MUST multiply the parent line quantity by the component quantity per bundle, while persisted composition quantities that already represent totals MUST remain unchanged.

#### Scenario: Transaction detail uses parent-scaled snapshot quantity
- **WHEN** transaction detail composition falls back to a snapshot for a parent bundle line with quantity 2 and a component quantity per bundle of 1
- **THEN** the detail page SHALL display the component quantity as `x2`

#### Scenario: Transaction detail preserves persisted total quantity
- **WHEN** transaction detail composition resolves a persisted total component quantity of 2
- **THEN** the detail page SHALL display the component quantity as `x2` without multiplying it again

