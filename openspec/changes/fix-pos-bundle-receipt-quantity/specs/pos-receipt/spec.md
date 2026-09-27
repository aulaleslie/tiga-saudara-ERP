# Spec Delta

## ADDED Requirements

### Requirement: Completed receipts SHALL display total bundle component quantities
The POS receipt and receipt reprint SHALL display each bundle component's exact total quantity for the parent quantity sold. When composition is reconstructed from a per-bundle transaction snapshot, the displayed component quantity MUST equal the parent line quantity multiplied by the component quantity per bundle. A persisted Sales or dispatch quantity that already represents the total MUST NOT be multiplied again.

#### Scenario: Snapshot fallback scales a one-per-bundle component
- **WHEN** a completed receipt falls back to a transaction snapshot for a parent bundle line with quantity 2 and a component quantity per bundle of 1
- **THEN** the receipt SHALL display the component quantity as `x2`

#### Scenario: Snapshot fallback scales a multi-per-bundle component
- **WHEN** a completed receipt falls back to a transaction snapshot for a parent bundle line with quantity 2 and a component quantity per bundle of 2
- **THEN** the receipt SHALL display the component quantity as `x4`

#### Scenario: Persisted total is not multiplied twice
- **WHEN** persisted Sales or dispatch composition reports a total component quantity of 4 for a parent bundle line with quantity 2
- **THEN** the receipt SHALL display the component quantity as `x4`
- **AND** the receipt MUST NOT multiply that persisted total by the parent quantity again

